{include file="user-ui/header.tpl"}

<div class="row">
    <div class="col-md-8">
        <div class="box box-primary box-solid mb30">
            <div class="box-header">
                <h4 class="box-title">{Lang::T("Recharge a friend")}</h4>
            </div>
            <div class="box-body p-0">
                <form method="post" onsubmit="return askConfirm()" role="form" action="{$_url}plugin/recharge_cardsClientPost">
                    <div class="form-group">
                        <div class="col-sm-5">
                            <input type="text" id="username" name="username" class="form-control" required
                                placeholder="{Lang::T('Username')}">
                        </div>
                        <div class="col-sm-5">
                            <input type="number" id="card_number" name="card_number" autocomplete="off"
                                class="form-control" required placeholder="{Lang::T('Enter the Card PIN')}">
                        </div>
                        <div class="form-group col-sm-2" align="center">
                            <button class="btn btn-success btn-block" id="sendBtn" type="submit" name="recharge"
                                onclick="return confirm('{Lang::T(" Are You Sure?")}')"><i
                                    class="glyphicon glyphicon-send"></i></button>
                        </div>
                    </div>
                </form>
            </div>
            <div class="box-header">
                <h4 class="box-title">{Lang::T("Redeem Recharge Card")}</h4>
            </div>
            <div class="box-body p-0">
                <form method="post" role="form" action="{$_url}plugin/recharge_cardsClientPost">
                    <div class="form-group">
                        <div class="col-sm-10">
                            <div class="input-group">
                                <input type="text" class="form-control" id="card_number" name="card_number" value=""
                                    placeholder="{Lang::T('Enter the Card PIN')}">
                                <span class="input-group-btn">
                                    <a class="btn btn-default" href="{APP_URL}/scan/?back={urlencode($_url)}{urlencode("
                                        plugin/recharge_cardsClient&card=")}"><i class="glyphicon glyphicon-qrcode"></i></a>
                                </span>
                            </div>
                        </div>
                        <div class="form-group col-sm-2" align="center">
                            <button class="btn btn-success btn-block" type="submit" name="recharge"><i
                                    class="glyphicon glyphicon-send"></i></button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

{include file="user-ui/footer.tpl"}