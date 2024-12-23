<?php

register_menu("Recharge Cards", true, "recharge_cards", 'CARDS', '', '', "");
register_menu("Recharge", false, "recharge_cardsClient", 'AFTER_INBOX', 'ion ion-card', '', "");




try {
    $db = ORM::get_db();
    $tableCheckQuery = "
        CREATE TABLE IF NOT EXISTS tbl_recharge_cards (
            id INT AUTO_INCREMENT PRIMARY KEY,
            card_number VARCHAR(255) UNIQUE NOT NULL,
            serial_number VARCHAR(255) UNIQUE NOT NULL,
            value DECIMAL(10, 2) NOT NULL,
            status ENUM('active', 'used') DEFAULT 'active',
            generated_by INT NOT NULL DEFAULT 0 COMMENT 'id admin',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            used_by INT NOT NULL DEFAULT 0 COMMENT 'None',
            used_at TIMESTAMP NULL DEFAULT NULL
        );

        CREATE TABLE IF NOT EXISTS tbl_recharge_lock (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            failed_attempts INT DEFAULT 0,
            last_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            locked_until TIMESTAMP NULL DEFAULT NULL
        );
    ";

    $db->exec($tableCheckQuery);
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage();
} catch (Exception $e) {
    echo "An unexpected error occurred: " . $e->getMessage();
}
function recharge_cards()
{
    global $ui;
    _admin();
    $ui->assign('_title', 'Recharge Cards');
    $ui->assign('_system_menu', 'plan');
    $admin = Admin::_info();
    $ui->assign('_admin', $admin);

    if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin', 'Sales'])) {
        _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $number_of_cards = intval(_post('number_of_cards', 1));
        $lengthcode = intval(_post('lengthcode', 12));
        $card_value = floatval(_post('card_value'));
        $print = intval(_post('print_now', 0));

        if ($lengthcode <= 0) {
            r2($_SERVER['HTTP_REFERER'], 'e', Lang::T("Invalid length for card code."));
            exit;
        }

        if ($number_of_cards <= 0 || $card_value <= 0) {
            r2($_SERVER['HTTP_REFERER'], 'e', Lang::T("Invalid number of cards or card value."));
            exit;
        }

        for ($i = 0; $i < $number_of_cards; $i++) {

            $card_number = substr(str_shuffle(str_repeat('0123456789', $lengthcode)), 0, $lengthcode);
            $serial_number = uniqid();

            $card = ORM::for_table('tbl_recharge_cards')->create();
            $card->card_number = $card_number;
            $card->serial_number = $serial_number;
            $card->value = $card_value;
            $card->status = 'active';
            $card->generated_by = $admin['id'];
            try {
                $card->save();
                $cardIds[] = $card->id;
            } catch (Exception $e) {
                _log(Lang::T("Failed to save cards: ") . $e->getMessage());
                r2($_SERVER['HTTP_REFERER'], 'e', Lang::T("An error occurred while while saving cards, check logs for more info"));
                return;
            }
        }

        if ($print) {
            recharge_cardsPrint($cardIds);
            r2($_SERVER['HTTP_REFERER'], 's', Lang::T("$number_of_cards cards generated and Printed Successfully"));
        } else {
            r2($_SERVER['HTTP_REFERER'], 's', Lang::T("$number_of_cards cards generated successfully."));
        }
    }

    $cards = ORM::for_table('tbl_recharge_cards')
        ->left_outer_join('tbl_users', ['tbl_recharge_cards.generated_by', '=', 'tbl_users.id'])
        ->left_outer_join('tbl_customers', ['tbl_recharge_cards.used_by', '=', 'tbl_customers.id'])
        ->select('tbl_recharge_cards.*')
        ->select('tbl_users.fullname', 'admin_name')
        ->select('tbl_customers.fullname', 'customer_name')
        ->select('tbl_customers.id', 'customer_id')
        ->find_many();

    if ($cards === false) {
        _log("Error fetching recharge cards.");
    }

    $lockouts = ORM::for_table('tbl_recharge_lock')
        ->left_outer_join('tbl_customers', ['tbl_recharge_lock.user_id', '=', 'tbl_customers.id'])
        ->select('tbl_recharge_lock.*')
        ->select('tbl_customers.fullname', 'customer_name')
        ->find_many();

    if ($lockouts === false) {
        _log("Error fetching lockout information.");
    }

    $ui->assign('lockouts', $lockouts);
    $ui->assign('cards', $cards);
    $ui->assign('xheader', '<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.11.3/css/jquery.dataTables.min.css">');
    $ui->display('recharge_cards.tpl');
}
function recharge_cardsPrint($cardIds = null)
{
    global $config;

    // Build the query to fetch cards
    $query = ORM::for_table('tbl_recharge_cards');

    if ($cardIds === null) {
        $cards = $query->find_many();
    } else {
        $query->where_in('tbl_recharge_cards.id', $cardIds);
        $cards = $query->find_many();
    }

    if (empty($cards)) {
        r2(U . "plugin/recharge_cards", 'e', Lang::T("No cards found for IDs: ") . implode(', ', $cardIds));
        exit;
    }

    $currency = htmlspecialchars($config['currency_code']);
    $cards_per_page = 50;
    $html = '';

    $card_count = 0;
    $UPLOAD_PATH = 'system' . DIRECTORY_SEPARATOR . 'uploads';
    $recharge_cards_temp = $UPLOAD_PATH . DIRECTORY_SEPARATOR . "recharge_cards_temp.json";

    if (file_exists($recharge_cards_temp)) {
        $json_data = file_get_contents($recharge_cards_temp);
        $json_data_array = json_decode($json_data, true);

        if ($json_data_array && isset($json_data_array['card_template'])) {
            $template = htmlspecialchars_decode($json_data_array['card_template']);
        } else {
            // Fallback template if JSON file does not contain template
            $template = '<style type="text/css">
                .card-container {
                    width: 250px;
                    height: 85px;
                    border: 1px solid #000;
                    font-family: Arial, sans-serif;
                    font-size: 10px;
                    margin-bottom: 5px;
                    display: flex;
                    background-color: #f7f7f7;
                }
                .price-bar {
                    width: 15px;
                    background-color: #ff8c00;
                    color: white;
                    text-align: center;
                    font-weight: bold;
                    padding: 5px 2px;
                    writing-mode: vertical-rl;
                    transform: rotate(180deg);
                }
                .details {
                    flex: 1;
                    padding: 5px;
                }
                .details .code {
                    font-size: 14px;
                    font-weight: bold;
                    text-align: center;
                    margin-bottom: 2px;
                }
                .details .info {
                    font-size: 9px;
                    margin-bottom: 2px;
                }
                .qrcode {
                    width: 68px;
                    height: 68px;
                    margin: auto;
                    padding: 5px;
                }
                .qrcode img {
                    width: 100%;
                    height: auto;
                }
            </style>
        <div class="card-container">
            <div class="price-bar">[[currency]][[value]]</div>
            <div class="details">
                <div class="code">[[card_pin]]</div>
                <div class="info">To recharge visit the link below</div>
                <div class="info">[[url]] </div>
                <div class="info">SN: [[serial]]</div>
                <div class="info">Thank you for choosing our service</div>
            </div>
            <div class="qrcode">[[qrcode]]</div>
        </div>';
        }
    } else {
        // Default template if JSON file does not exist
        $template = '<style type="text/css">
            .card-container {
                width: 250px;
                height: 85px;
                border: 1px solid #000;
                font-family: Arial, sans-serif;
                font-size: 10px;
                margin-bottom: 5px;
                display: flex;
                background-color: #f7f7f7;
            }
            .price-bar {
                width: 15px;
                background-color: #ff8c00;
                color: white;
                text-align: center;
                font-weight: bold;
                padding: 5px 2px;
                writing-mode: vertical-rl;
                transform: rotate(180deg);
            }
            .details {
                flex: 1;
                padding: 5px;
            }
            .details .code {
                font-size: 14px;
                font-weight: bold;
                text-align: center;
                margin-bottom: 2px;
            }
            .details .info {
                font-size: 9px;
                margin-bottom: 2px;
            }
            .qrcode {
                width: 68px;
                height: 68px;
                margin: auto;
                padding: 5px;
            }
            .qrcode img {
                width: 100%;
                height: auto;
            }
        </style>
        <div class="card-container">
            <div class="price-bar">[[currency]][[value]]</div>
            <div class="details">
                <div class="code">[[card_pin]]</div>
                <div class="info">To recharge visit the link below</div>
                <div class="info">[[url]] </div>
                <div class="info">SN: [[serial]]</div>
                <div class="info">Thank you for choosing our service</div>
            </div>
            <div class="qrcode">[[qrcode]]</div>
        </div>';
    }

    foreach ($cards as $card) {
        $card_count++;
        $url_recharge = APP_URL . urlencode("/?route=plugin/recharge_cardsClient&card={$card->card_number}");
        $url = APP_URL . '/?route=login';
        $qrCode = "<img src=\"qrcode/?data={$url_recharge}\" alt=\"QR Code\">";
        $current_card = str_replace(
            ['[[currency]]', '[[value]]', '[[card_pin]]', '[[url]]', '[[serial]]', '[[qrcode]]'],
            [$currency, htmlspecialchars($card->value), htmlspecialchars($card->card_number), $url, htmlspecialchars($card->serial_number), $qrCode],
            $template
        );

        $html .= $current_card;

        if ($card_count % $cards_per_page == 0 && $card_count < count($cards)) {
            $html .= '<div class="pagebreak"></div>';
        }
    }

    if (empty($html)) {
        r2(U . "plugin/recharge_cards", 'e', Lang::T("Error generating card preview. No content."));
        exit;
    }

    // Render the HTML for preview
    echo "<div style=\"display: flex; flex-wrap: wrap; justify-content: space-between;\">$html</div>";
    echo '<button onclick="window.print()">Print</button>';
}


function recharge_cards_print()
{
    $admin = Admin::_info();

    if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin', 'Sales'])) {
        _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'GET') {
        $cardIds = json_decode($_POST['cardIds'], true) ?? [$_GET['card_id']];

        if (is_array($cardIds) && !empty($cardIds)) {
            recharge_cardsPrint($cardIds);
        } else {
            r2($_SERVER['HTTP_REFERER'], 'e', Lang::T("No card ID provided."));
            exit;
        }
    } else {
        r2($_SERVER['HTTP_REFERER'], 'e', Lang::T("Invalid request method"));
    }
}
function recharge_cards_delete()
{
    $admin = Admin::_info();

    if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin', 'Sales'])) {
        _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $cardIds = json_decode($_POST['cardIds'], true);

        if (is_array($cardIds) && !empty($cardIds)) {
            // Delete cards from the database
            ORM::for_table('tbl_recharge_cards')
                ->where_in('id', $cardIds)
                ->delete_many();
            return ['status' => 'success', 'message' => Lang::T("Cards Deleted Successfully.")];
        } else {
            return ['status' => 'error', 'message' => Lang::T("Invalid or missing card IDs.")];
        }
    } else {
        return ['status' => 'error', 'message' => Lang::T("Invalid request method.")];
    }
}
function recharge_cards_sendCard()
{
    global $config;
    $admin = Admin::_info();

    if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin', 'Sales'])) {
        _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $cardId = $_POST['cardId'] ?? null;
        $phoneNumber = $_POST['phoneNumber'] ?? null;
        $sendVia = $_POST['method'] ?? 'sms';
        $UPLOAD_PATH = 'system' . DIRECTORY_SEPARATOR . 'uploads';
        $recharge_cards_temp = $UPLOAD_PATH . DIRECTORY_SEPARATOR . "recharge_cards_temp.json";

        $default_message = "Dear Customer,\r\nHere is your Recharge Card Details:\r\nCard PIN: [[card_number]]\r\nCard Value: [[value]]\r\n\r\n[[company]]";

        if (file_exists($recharge_cards_temp)) {
            $json_data = file_get_contents($recharge_cards_temp);
            if ($json_data !== false) {
                $json_data_array = json_decode($json_data, true);
                $messageContent = $json_data_array['card_send'] ?? $default_message;
            } else {
                $messageContent = $default_message;
            }
        } else {
            $messageContent = $default_message;
        }


        if (!$cardId || !$phoneNumber) {
            _log("Debug: card ID: $cardId, Phone Number: $phoneNumber");
            r2($_SERVER['HTTP_REFERER'], 'e', Lang::T("Invalid or missing card ID or phone number."));
            exit;
        }

        if ($cardId && $phoneNumber) {
            $card = ORM::for_table('tbl_recharge_cards')->find_one($cardId);

            if ($card) {
                $cardPin = $card->card_number;
                // Replace placeholders with actual values
                $message = str_replace('[[company]]', $config['CompanyName'], $messageContent);
                $message = str_replace('[[card_number]]', $cardPin, $message);
                $message = str_replace('[[value]]', $card->value, $message);

                $channels = [
                    'sms' => [
                        'enabled' => $sendVia == 'sms' || $sendVia == 'both',
                        'method' => 'Message::sendSMS',
                        'args' => [$phoneNumber, $message] 
                    ],
                    'whatsapp' => [
                        'enabled' => $sendVia == 'wa' || $sendVia == 'both',
                        'method' => 'Message::sendWhatsapp',
                        'args' => [$phoneNumber, $message]
                    ]
                ];

                try {
                    foreach ($channels as $channel => $channelData) {
                        if ($channelData['enabled']) {
                            try {
                                call_user_func_array($channelData['method'], $channelData['args']);
                            } catch (Exception $e) {
                                _log("Failed to send card PIN via $channel: " . $e->getMessage());
                            }
                        }
                    }

                    r2($_SERVER['HTTP_REFERER'], 's', Lang::T("Card PIN has been send successfully to: ") . $phoneNumber);
                } catch (Exception $e) {
                    r2($_SERVER['HTTP_REFERER'], 's', Lang::T("Failed to send card PIN to ") . $phoneNumber . ' ' . $e->getMessage());
                    _log(Lang::T("Failed to send card PIN to ") . $phoneNumber . ' ' . $e->getMessage());
                }
            } else {
                echo json_encode(['status' => 'error', 'message' => 'card not found.']);
                r2($_SERVER['HTTP_REFERER'], 's', Lang::T("card not found."));
            }
        } else {
            r2($_SERVER['HTTP_REFERER'], 's', Lang::T("Invalid or missing card ID or phone number."));
        }
    } else {
        r2($_SERVER['HTTP_REFERER'], 'e', Lang::T("Invalid request method"));
        exit;
    }
    exit;
}
function recharge_cards_process($card_number, $current_user, $target_user_id = null,  $is_admin = false)
{
    $user_id = $target_user_id ?: $current_user['id'];
    $lockout_user_id = $current_user['id'];

    // Bypass security check for admin
    if (!$is_admin) {
        $security = ORM::for_table('tbl_recharge_lock')
            ->where('user_id', $lockout_user_id)
            ->find_one();

        if (!$security) {
            $security = ORM::for_table('tbl_recharge_lock')->create();
            $security->user_id = $lockout_user_id;
            $security->failed_attempts = 0;
            $security->locked_until = null;
            $security->save();
        }

        if ($security->locked_until && strtotime($security->locked_until) > time()) {
            $remainingLockoutTime = max(0, strtotime($security->locked_until) - time());
            $lockoutMinutes = ceil($remainingLockoutTime / 60);
            return ['status' => 'error', 'message' => Lang::T("You are temporarily locked out. Please try again in {$lockoutMinutes} minutes.")];
        }
    }

    $card = ORM::for_table('tbl_recharge_cards')
        ->where('card_number', $card_number)
        ->find_one();

    if ($card) {
        if ($card->status == 'used' && $card->used_by != $user_id) {
            if (!$is_admin) {
                $security->failed_attempts += 1;
                $security->last_attempt = date('Y-m-d H:i:s');

                if ($security->failed_attempts >= 5) {
                    $security->locked_until = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                    $security->save();
                    return ['status' => 'error', 'message' =>  Lang::T("Too many failed attempts. You are now locked out for 15 minutes.")];
                }

                $security->save();
                $remainingAttempts = 5 - $security->failed_attempts;
                return ['status' => 'error', 'message' =>  Lang::T("This card has already been used. You have {$remainingAttempts} attempt(s) left before lockout.")];
            } else {
                return ['status' => 'error', 'message' => Lang::T("This card has already been used by another user.")];
            }
        }

        if ($card->status == 'used' && $card->used_by == $user_id) {
            $security->failed_attempts += 1;
            $security->last_attempt = date('Y-m-d H:i:s');

            if ($security->failed_attempts >= 5) {
                $security->locked_until = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                $security->save();
                return ['status' => 'error', 'message' => Lang::T("Too many failed attempts. You are now locked out for 15 minutes.")];
            }

            $security->save();
            $remainingAttempts = 5 - $security->failed_attempts;
            return ['status' => 'error', 'message' => Lang::T("This card has already been used by you. You have {$remainingAttempts} attempt(s) left before lockout.")];
        }

        // Reset security on successful recharge (only if not admin)
        if (!$is_admin) {
            $security->failed_attempts = 0;
            $security->locked_until = null;
            $security->save();
        }

        $user = ORM::for_table('tbl_customers')->find_one($user_id);
        $user->balance += $card->value;
        $user->save();

        $card->status = 'used';
        $card->used_by = $user_id;
        $card->used_at = date('Y-m-d H:i:s');
        $card->save();
        recharge_cardsInvoice($user, $card);
        // Notify the recipient
        if ($target_user_id && $target_user_id != $current_user['id']) {
            $sender_name = $current_user['fullname'];
            $recipient_name = $user->fullname;
            $message = Lang::T("Your account has been recharged by {$sender_name}. Your new balance is {$user->balance}");
            if (recharge_cardsInbox($target_user_id, $message)) {
            } else {
                _log(Lang::T("Failed to notify {$recipient_name} about recharge."));
            }
            recharge_cardsNotify($target_user_id['id'], $message);
        }

        // Notify the sender
        if ($target_user_id && $target_user_id != $current_user['id']) {
            $recipient_name = $user->fullname;
            $message = Lang::T("You have successfully recharged {$recipient_name}'s account with {$card->value}.");
            if (recharge_cardsInbox($current_user['id'], $message)) {
            } else {
                _log(Lang::T("Failed to notify {$sender_name} about recharge."));
            }
            recharge_cardsNotify($current_user['id'], $message);
        }

        if ($target_user_id && $target_user_id != $current_user['id']) {
            _log(Lang::T("{$sender_name} have successfully recharged {$recipient_name}'s account with {$card->value}."));
            return ['status' => 'success', 'message' =>  Lang::T("You have successfully recharged {$recipient_name}'s account with {$card->value}.")];
        } else {
            _log(Lang::T("{$current_user['fullname']} have successfully recharged {$card->value}"));
            return ['status' => 'success', 'message' =>  Lang::T("Recharge successful! Your new balance is {$user->balance}")];
        }
        
    } else {
        if (!$is_admin) {
            $security->failed_attempts += 1;
            $security->last_attempt = date('Y-m-d H:i:s');

            if ($security->failed_attempts >= 5) {
                $security->locked_until = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                $security->save();
                return ['status' => 'error', 'message' => Lang::T("Too many failed attempts. You are now locked out for 15 minutes.")];
            }

            $security->save();
            $remainingAttempts = 5 - $security->failed_attempts;
            return ['status' => 'error', 'message' => Lang::T("Invalid or already used recharge card. You have {$remainingAttempts} attempt(s) left before lockout.")];
        } else {
            return ['status' => 'error', 'message' =>  Lang::T("Invalid or already used recharge card.")];
        }
    }
}
function recharge_cardsNotify($user_id, $msg)
{
    $message = htmlspecialchars_decode($msg);
    $user = ORM::for_table('tbl_customers')->find_one($user_id);
    $phone = $user->phonenumber;
    if ($phone) {
        try {
            sendSMS($phone, $message);
            sendWhatsapp($phone, $message);
        } catch (Exception $e) {
            _log("Recharg Card System failed to send SMS to: {$phone}: {$e->getMessage()}");
        }
    }
}
function recharge_cardsClient()
{
    global $ui;
    _auth();
    $ui->assign('_title', 'Recharge Account');
    $ui->assign('_system_menu', '');
    $user = User::_info();
    $ui->assign('_user', $user);
    if ($user['status'] != 'Active') {
        _alert(Lang::T('This account status') . ' : ' . Lang::T($user['status']), 'danger', "");
    }

    $ui->display('recharge_cardsClient.tpl');
}
function recharge_cardsClientPost()
{
    _auth();
    $user = User::_info();

    if ($user['status'] != 'Active') {
        _alert(Lang::T('This account status') . ' : ' . Lang::T($user['status']), 'danger', "");
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
        r2(U . 'plugin/recharge_cardsClient', 'e', Lang::T("Invalid request method"));
        return;
    }

    if (
        (empty($_POST['card_number']) && empty($_GET['card'])) ||
        ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['recharge'])) ||
        ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['card']))
    ) {
        r2(U . 'plugin/recharge_cardsClient', 'e', Lang::T("No data provided"));
        return;
    }

    $card_number = $_POST['card_number'] ?? $_GET['card'];
    if (Validator::UnsignedNumber($card_number) == false) {
        r2(U . 'plugin/recharge_cardsClient', 'e', Lang::T("Card Number must be a number"));
        return;
    }

    if (!empty($_POST['username'])) {
        $username = $_POST['username'];

        if ($user['username'] == $username) {
            r2(U . 'plugin/recharge_cardsClient', 'e', Lang::T('You cant recharge yourself using this channel'));
        }

        $recipient = ORM::for_table('tbl_customers')->where('username', $username)->find_one();
        if (!$recipient) {
            r2(U . 'plugin/recharge_cardsClient', 'e', Lang::T("Invalid recipient username."));
            return;
        }

        if ($recipient['status'] != 'Active') {
            r2(U . 'plugin/recharge_cardsClient', 'e', Lang::T('This account status is') . ' : ' . Lang::T($user['status']) . ' ' . Lang::T('and it cant be recharged'));
        }

        $result = recharge_cards_process($card_number, $user, $recipient->id);
    } else {
        $result = recharge_cards_process($card_number, $user);
    }

    if ($result['status'] === 'error') {
        r2(U . 'plugin/recharge_cardsClient', 'e', $result['message']);
    } else {
        r2(U . 'plugin/recharge_cardsClient', 's', $result['message']);
    }
}

function recharge_cardsUnblock()
{
    _admin();
    $admin = Admin::_info();

    if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin', 'Sales'])) {
        _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        exit;
    }
    $userId = (int) _req('id');

    $security = ORM::for_table('tbl_recharge_lock')
        ->where('user_id', $userId)
        ->find_one();

    if ($security) {
        $security->locked_until = null;
        $security->failed_attempts = 0;
        $security->save();
        r2($_SERVER['HTTP_REFERER'], 's', Lang::T("Account unlock successful."));
    } else {
        r2($_SERVER['HTTP_REFERER'], 'e', Lang::T("Account not found."));
    }
    exit;
}
function recharge_cardsInbox($customer_id, $message)
{
    $date_created = date('Y-m-d H:i:s');
    $date_read = null;
    $subject = 'Recharge Notification';
    $from = 'System';

    try {
        $inbox = ORM::for_table('tbl_customers_inbox')->create();
        $inbox->set('customer_id', $customer_id)
            ->set('subject', $subject)
            ->set('body', htmlspecialchars($message))
            ->set('date_created', $date_created)
            ->set('date_read', $date_read)
            ->set('from', $from);
        $inbox->save();
        return true;
    } catch (Exception $e) {
        _log("Failed to create inbox message: " . $e->getMessage());
        return false;
    }
}


function recharge_cardsInvoice($customer, $card, $admin = false)
{
    $t = ORM::for_table('tbl_transactions')->create();
    $t->invoice = "INV-" . Package::_raid();
    $t->username = $customer['username'];
    $t->plan_name = 'Recharge Card ' . $card['value'];
    $t->price = $card['value'];
    $t->recharged_on = date("Y-m-d");
    $t->recharged_time = date("H:i:s");
    $t->expiration = date("Y-m-d");
    $t->time = date("H:i:s");
    $t->method = "Recharge Card";
    $t->routers = 'balance';
    $t->type = "Balance";
    $t->admin_id = $admin ? ($admin['id']) : '0';
    try {
        $t->save();
        return true;
    } catch (Exception $e) {
        _log("Failed to create invoice: " . $e->getMessage());
        return false;
    }
}
