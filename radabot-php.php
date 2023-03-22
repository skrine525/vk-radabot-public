<?php

// Логирование ошибок в нужную директорию
ini_set("log_errors", "On");  
ini_set('error_log', 'log/php-errors.log');
ini_set('error_reporting', E_ALL); 

require("radabot/php-core/bot.php"); // Подгружаем PHP код бота

set_time_limit(5); // Время жизни скрипта - 5 секунд

if(array_key_exists(1, $argv) && array_key_exists(2, $argv)){
	switch($argv[1]){
		case 'cmd':
			$data = json_decode($argv[2]);
			if($data !== false)
				bot_handle_event($data, true, false);
			break;

		case 'hndl':
			$data = json_decode($argv[2]);
			if($data !== false)
				bot_handle_event($data, false, true);
			break;
	}
}

?>