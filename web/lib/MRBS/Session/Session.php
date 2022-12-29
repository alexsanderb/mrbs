<?php
namespace MRBS\Session;

use MRBS\SessionHandlerDb;
use MRBS\SessionHandlerDbException;
use MRBS\User;
use SessionHandler;
use function MRBS\db;
use function MRBS\db_schema_version;
use function MRBS\get_cookie_path;

abstract class Session
{

  public function __construct()
  {
    global $auth;

    // Start up sessions
    // Default to the behaviour of previous versions of MRBS, use only
    // session cookies - no persistent cookie.
    $lifetime = $auth['session_php']['session_expire_time'] ?? 0;
    $this->init($lifetime);
  }


  // Normally there's no need to call init() from outside the Session classes.
  // It only needs to be called to restart sessions, after for example a user
  // has been logged off and you need to use session variables.
  public function init(int $lifetime) : void
  {
    global $auth;

    if (session_status() === PHP_SESSION_ACTIVE)
    {
      // We've already started sessions
      return;
    }

    // Set some session settings, as a defence against session fixation.
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');  // Only available since PHP 5.5.2, but does no harm before then
    ini_set('session.use_trans_sid', '0');

    $cookie_path = get_cookie_path();

    // We don't want the session garbage collector to delete the session before it has expired
    if ($lifetime !== 0)
    {
      ini_set('session.gc_maxlifetime', max(ini_get('session.gc_maxlifetime'), $lifetime));
    }

    session_name($auth['session_php']['session_name']);  // call before session_set_cookie_params() - see PHP manual
    session_set_cookie_params($lifetime, $cookie_path);

    $current_db_schema_version = db_schema_version();
    // The session table was created in Upgrade 56.   Before that we will ignore any errors
    // to do with DB sessions.
    $session_table_should_exist = ($current_db_schema_version >= 56);

    try
    {
      // The DB session handler uses locks and because we use locks elsewhere
      // this means we need support for multiple locks.  We need to test now,
      // rather than catching an exception, because resetting the session
      // handler will reset the session id causing us to lose session data.
      if (db()->supportsMultipleLocks())
      {
        $handler = new SessionHandlerDb();
        session_set_save_handler($handler, true);
      }
      $session_started = session_start();
    }
    catch(SessionHandlerDbException $e)
    {
      if ($session_table_should_exist &&
        ($e->getCode() === SessionHandlerDbException::TABLE_NOT_EXISTS))
      {
        trigger_error($e->getMessage(), E_USER_WARNING);
        $message = "Could not start DB sessions, trying ordinary PHP sessions.";
        trigger_error($message, E_USER_WARNING);
      }
      $session_started = false;
    }

    if ($session_started === false)
    {
      $handler = new SessionHandler();
      session_set_save_handler($handler, true);

      if (false === session_start())
      {
        throw new \Exception("MRBS: could not start sessions");
      }
    }
  }


  public function get(string $name)
  {
    return $_SESSION[$name] ?? null;
  }


  public function isset(string $name) : bool
  {
    return isset($_SESSION[$name]);
  }

  public function set(string $name, $value) : void
  {
    $_SESSION[$name] = $value;
  }


  public function unset(string $name) : void
  {
    unset($_SESSION[$name]);
  }


  // Returns the currently logged-in user
  abstract public function getCurrentUser() : ?User;

  // Allows this to be extended with strategies for getting the referer when
  // HTTP_REFERER is going to be unreliable, eg when the Referrer-Policy is
  // set to strict-origin.
  public function getReferrer() : ?string
  {
    global $server;

    return $server['HTTP_REFERER'] ?? null;
  }


  // Updates the current and previous pages
  public function updatePage(string $url) : void
  {
  }

}
