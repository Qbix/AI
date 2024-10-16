<?php

function AI_webhook_post()
{
    $uri = Q_Dispatcher::uri();
    $headerValue = Q::ifset($_SERVER, 'HTTP_X_WEBHOOK_SECRET', null);
    if (!$headerValue) {
        throw new Users_Exception_NotAuthorized();
    }
    list($platform, $appId) = explode('-', $headerValue);
    if ($headerValue !== Users::secretToken($platform, $appId)) {
        throw new Users_Exception_NotAuthorized();
    }
    Q_Response::flushEarly();
    Q::event("AI/webhook/{$uri->type}/{$uri->task}", compact(
        'platform', 'appId'
    ));
}