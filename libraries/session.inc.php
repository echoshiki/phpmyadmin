<?php
/* vim: set expandtab sw=4 ts=4 sts=4: */
/**
 * session handling
 *
 * @todo    add an option to use mm-module for session handler
 *
 * @package PhpMyAdmin
 * @see     https://php.net/session
 */
if (! defined('PHPMYADMIN')) {
    exit;
}

require_once 'libraries/session.lib.php';

// verify if PHP supports session, die if it does not

if (!@function_exists('session_name')) {
    PMA_warnMissingExtension('session', true);
} elseif (ini_get('session.auto_start') !== '' && session_name() != 'phpMyAdmin') {
    // Do not delete the existing session, it might be used by other
    // applications; instead just close it.
    session_write_close();
}

// disable starting of sessions before all settings are done
// does not work, besides how it is written in php manual
//ini_set('session.auto_start', '0');

// session cookie settings
session_set_cookie_params(
    0, $GLOBALS['PMA_Config']->getCookiePath(),
    '', $GLOBALS['PMA_Config']->isHttps(), true
);

// cookies are safer (use @ini_set() in case this function is disabled)
@ini_set('session.use_cookies', 'true');

// optionally set session_save_path
$path = $GLOBALS['PMA_Config']->get('SessionSavePath');
if (!empty($path)) {
    session_save_path($path);
}

// but not all user allow cookies
@ini_set('session.use_only_cookies', 'false');
// do not force transparent session ids, see bug #3398788
//@ini_set('session.use_trans_sid', 'true');
@ini_set(
    'url_rewriter.tags',
    'a=href,frame=src,input=src,form=fakeentry,fieldset='
);
//ini_set('arg_separator.output', '&amp;');

// delete session/cookies when browser is closed
@ini_set('session.cookie_lifetime', '0');

// warn but don't work with bug
@ini_set('session.bug_compat_42', 'false');
@ini_set('session.bug_compat_warn', 'true');

// use more secure session ids
@ini_set('session.hash_function', '1');

// some pages (e.g. stylesheet) may be cached on clients, but not in shared
// proxy servers
session_cache_limiter('private');

// start the session
// on some servers (for example, sourceforge.net), we get a permission error
// on the session data directory, so I add some "@"


function PMA_sessionFailed($errors)
{
    $messages = array();
    foreach ($errors as $error) {
        $messages[] = $error->getMessage();
    }

    /*
     * Session initialization is done before selecting language, so we
     * can not use translations here.
     */
    PMA_fatalError(
        'Error during session start; please check your PHP and/or '
        . 'webserver log file and configure your PHP '
        . 'installation properly. Also ensure that cookies are enabled '
        . 'in your browser.'
        . '<br /><br />'
        . implode('<br /><br />', $messages)
    );
}

// See bug #1538132. This would block normal behavior on a cluster
//ini_set('session.save_handler', 'files');

$session_name = 'phpMyAdmin';
@session_name($session_name);

// on first start of session we check for errors
// f.e. session dir cannot be accessed - session file not created
$orig_error_count = $GLOBALS['error_handler']->countErrors(false);

$session_result = session_start();

if ($session_result !== true
    || $orig_error_count != $GLOBALS['error_handler']->countErrors(false)
) {
    setcookie($session_name, '', 1);
    $errors = $GLOBALS['error_handler']->sliceErrors($orig_error_count);
    PMA_sessionFailed($errors);
}
unset($orig_error_count, $session_result);

/**
 * Disable setting of session cookies for further session_start() calls.
 */
@ini_set('session.use_cookies', 'true');

/**
 * Token which is used for authenticating access queries.
 * (we use "space PMA_token space" to prevent overwriting)
 */
if (! isset($_SESSION[' PMA_token '])) {
    PMA_generateToken();

    /**
     * Check for disk space on session storage by trying to write it.
     *
     * This seems to be most reliable approach to test if sessions are working,
     * otherwise the check would fail with custom session backends.
     */
    $orig_error_count = $GLOBALS['error_handler']->countErrors();
    session_write_close();
    if ($GLOBALS['error_handler']->countErrors() > $orig_error_count) {
        $errors = $GLOBALS['error_handler']->sliceErrors($orig_error_count);
        PMA_sessionFailed($errors);
    }
    session_start();
}
