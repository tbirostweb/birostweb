<?php
// CLI seulement : aucune action depuis HTTP.
if(PHP_SAPI !== 'cli'){http_response_code(404);exit;}
require __DIR__.'/inc/contact_lib.php';
contact_load_env();
$base=rtrim(contact_env('CONTACT_STATE_DIR') ?? sys_get_temp_dir(), '/'); foreach (['contact_form_rl'=>86400,'contact_form_global'=>86400,'altcha_used'=>7200] as $sub=>$seconds) foreach(glob($base.'/'.$sub.'/*') ?: [] as $f) if(is_file($f) && filemtime($f)<time()-$seconds) @unlink($f); $logs=rtrim(contact_env('CONTACT_LOG_DIR') ?? sys_get_temp_dir(), '/'); foreach(glob($logs.'/contact_form-*.log') ?: [] as $f) if(is_file($f) && filemtime($f)<time()-30*86400) @unlink($f);
