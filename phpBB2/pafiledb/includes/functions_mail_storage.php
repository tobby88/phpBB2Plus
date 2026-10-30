<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_mutation_storage.php';
require_once dirname(dirname(dirname(__FILE__))) . '/includes/functions_mail_delivery.php';

class PaFileDbMailFailure extends PaFileDbMutationFailure {}
function pafiledb_mail_fail($key = 'PA_mail_failed') { throw new PaFileDbMailFailure($key); }
function pafiledb_mail_translate($error)
{
    $key = $error->getMessage();
    if (strpos($key, 'Mail_') === 0) { $key = 'PA_mail_' . substr($key, 5); }
    pafiledb_mail_fail($key);
}
function pafiledb_mail_call($function, $arguments)
{
    try { return call_user_func_array($function, $arguments); }
    catch (PhpbbMailFailure $error) { pafiledb_mail_translate($error); }
}
function pafiledb_mail_input($post, $name) { return pafiledb_mail_call('phpbb_mail_input', array($post,$name)); }
function pafiledb_mail_text($value, $limit, $header = false, $required = true) { return pafiledb_mail_call('phpbb_mail_text', array($value,$limit,$header,$required)); }
function pafiledb_mail_address($value) { return pafiledb_mail_call('phpbb_mail_address', array($value)); }
function pafiledb_mail_payload($post, $logged) { return pafiledb_mail_call('phpbb_mail_payload', array($post,$logged)); }

class PaFileDbMailStore extends PhpbbMailStore
{
    use PaFileDbCurrentContext;
    protected $file_id;
    function __construct($database, $actor, $file_id) { $this->file_id = $file_id; parent::__construct($database, $actor); }
    protected function storage_tables()
    {
        return array(PA_FILES_TABLE,PA_CATEGORY_TABLE,PA_AUTH_ACCESS_TABLE,USERS_TABLE,SESSIONS_TABLE,
            USER_GROUP_TABLE,GROUPS_TABLE,CONFIG_TABLE,CTRACKER_CONFIG,CTRACKER_RATE_LIMITS,BANLIST_TABLE,DISALLOW_TABLE,WORDS_TABLE);
    }
    protected function authorize_message($data, $user)
    {
        $context = $this->current_context();
        if (!$this->authorized($context['category']['auth_email'], 'auth_email', $context['access'], $context['user'])) { pafiledb_mail_fail('Not_Authorised'); }
        return $data;
    }
}
function pafiledb_send_recommendation($database, $file_id, $post, $factory)
{
    $owner = null;
    try {
        $actor = phpbb_mail_actor(true, $post); $file_id = pafiledb_mutation_id($file_id);
        $data = pafiledb_mail_payload($post, $actor['logged']);
        $address = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
        $owner = new PaFileDbMailStore($database, $actor, $file_id);
        $owner->reserve($data, $address); $owner->deliver($factory);
        return $file_id;
    } catch (PaFileDbMailFailure $error) { throw $error; }
    catch (PhpbbMailFailure $error) { pafiledb_mail_translate($error); }
    catch (PaFileDbMutationFailure $error) {
        $key = $error->getMessage();
        pafiledb_mail_fail($key === 'PA_storage_failed' ? 'PA_mail_failed' : ($key === 'PA_storage_upgrade' ? 'PA_mail_upgrade' : $key));
    } catch (Exception $error) { pafiledb_mail_fail(); }
    catch (Error $error) { pafiledb_mail_fail(); }
    finally { if ($owner) { $owner->release(); } }
}
