<?php

///////////////////////////////////////////////////////////
/// API

namespace Roleplay {
	class ActWithHandler
	{
		// Константы
		const GENDER_FEMALE = 1;
		const GENDER_MALE = 2;

		// Базовые переменные
		private $db;
		private $data;
		private $argv;
		private $text_command;

		// Переменные параметров
		public $maleMessage;
		public $femaleMessage;
		public $maleMessageToMyself;
		public $femaleMessageToMyself;
		public $maleMessageToAll;
		public $femaleMessageToAll;
		public $maleMessageToOnline;
		public $femaleMessageToOnline;
		private $permittedMemberGender;
		private $memberGenderErrorMessage;

		// Описание
		private $allowDescription;

		function __construct($db, $data, $argv, $text_command)
		{
			$this->db = $db;
			$this->data = $data;
			$this->argv = $argv;
			$this->text_command = $text_command;

			$this->maleMessage = null;
			$this->femaleMessage = null;
			$this->maleMessageToMyself = null;
			$this->femaleMessageToMyself = null;
			$this->maleMessageToAll = null;
			$this->femaleMessageToAll = null;

			$this->permittedMemberGender = 0;
			$this->memberGenderErrorMessage = "";

			$this->allowDescription = false;
		}

		private function getArgv()
		{
			$text_command_argv_count = count(bot_parse_argv($this->text_command));					// Количество аргументов РП-команды
			$argv_count = count($this->argv);														// Количество аргументов полученного события
			$processed_argv = array();
			for ($i = $text_command_argv_count; $i < $argv_count; $i++)
				$processed_argv[] = $this->argv[$i];
			return $processed_argv;
		}

		public function setPermittedMemberGender($gender, $message)
		{
			if ($gender != ActWithHandler::GENDER_FEMALE && $gender != ActWithHandler::GENDER_MALE) {
				$debug_backtrace = debug_backtrace();
				error_log("Parameter gender is invalid in function {$debug_backtrace[0]["function"]} in {$debug_backtrace[0]["file"]} on line {$debug_backtrace[0]["line"]}");
				return false;
			}
			$this->permittedMemberGender = $gender;
			$this->memberGenderErrorMessage = $message;
		}

		public function allowDescription($state)
		{
			$this->allowDescription = $state;
		}

		private function generateDescriptionMessageVKScriptCode($message)
		{
			if ($this->allowDescription) {
				if ($message != '') {
					$formated_message = addslashes($message);
					return "var DESCRIPTION_MSG=\" {$formated_message}\";";
				} else
					return "var DESCRIPTION_MSG=\"\";";
			} else
				return "";
		}

		public function handle()
		{
			// Проверка главных переменных
			if (is_null($this->maleMessage)) {
				$debug_backtrace = debug_backtrace();
				error_log("Invalid parameter maleMessage while handling in {$debug_backtrace[0]["file"]} on line {$debug_backtrace[0]["line"]}");
				return false;
			} elseif (is_null($this->femaleMessage)) {
				$debug_backtrace = debug_backtrace();
				error_log("Invalid parameter femaleMessage while handling in {$debug_backtrace[0]["file"]} on line {$debug_backtrace[0]["line"]}");
				return false;
			} elseif (is_null($this->maleMessageToMyself)) {
				$debug_backtrace = debug_backtrace();
				error_log("Invalid parameter maleMessageToMyself while handling in {$debug_backtrace[0]["file"]} on line {$debug_backtrace[0]["line"]}");
				return false;
			} elseif (is_null($this->femaleMessageToMyself)) {
				$debug_backtrace = debug_backtrace();
				error_log("Invalid parameter femaleMessageToMyself while handling in {$debug_backtrace[0]["file"]} on line {$debug_backtrace[0]["line"]}");
				return false;
			}
			$argv = $this->getArgv(); // Получаем аргументы текущего запроса из сообщения
			$messagesModule = new \Bot\Messages($this->db);
			if (gettype($argv[0]) != "string" && !array_key_exists(0, $this->data->object->fwd_messages)) {
				$messagesModule->setAppealID($this->data->object->from_id);

				if ($this->allowDescription)
					$help_message_desc = " <описание>";
				else
					$help_message_desc = '';

				$messagesModule->sendSilentMessageWithListFromArray($this->data->object->peer_id, "%appeal%, Используйте:", array(
					"{$this->text_command} <имя>{$help_message_desc}",
					"{$this->text_command} <фамилия>{$help_message_desc}",
					"{$this->text_command} <имя и фамилия>{$help_message_desc}",
					"{$this->text_command} <id>{$help_message_desc}",
					"{$this->text_command} <упоминание>{$help_message_desc}",
					"{$this->text_command} <пер. сообщение>{$help_message_desc}",
					"{$this->text_command} <ник>{$help_message_desc}",
					"{$this->text_command} все{$help_message_desc}"
				));
				return false;
			}

			$member_id = 0;
			if (array_key_exists(0, $this->data->object->fwd_messages)) {
				$member_id = $this->data->object->fwd_messages[0]->from_id;
				$descriptionMessage = str_ireplace("\n", " ", bot_get_text_by_argv($argv, 0));
				$descriptionMessage_VKScript = $this->generateDescriptionMessageVKScriptCode($descriptionMessage);
			} elseif (bot_get_userid_by_mention($argv[0], $member_id)) {
				$descriptionMessage = str_ireplace("\n", " ", bot_get_text_by_argv($argv, 1));
				$descriptionMessage_VKScript = $this->generateDescriptionMessageVKScriptCode($descriptionMessage);
			} elseif (bot_get_userid_by_nick($this->db, $argv[0], $member_id)) {
				$descriptionMessage = str_ireplace("\n", " ", bot_get_text_by_argv($argv, 1));
				$descriptionMessage_VKScript = $this->generateDescriptionMessageVKScriptCode($descriptionMessage);
			} elseif (is_numeric($argv[0])) {
				$member_id = intval($argv[0]);
				$descriptionMessage = str_ireplace("\n", " ", bot_get_text_by_argv($argv, 1));
				$descriptionMessage_VKScript = $this->generateDescriptionMessageVKScriptCode($descriptionMessage);
			} else {
				$descriptionMessage = str_ireplace("\n", " ", bot_get_text_by_argv($argv, 1));
				$descriptionMessage_VKScript = $this->generateDescriptionMessageVKScriptCode($descriptionMessage);
			}


			if ($member_id > 0) {
				$messagesJson = json_encode(
					array(
						'male' => $this->maleMessage,
						'female' => $this->femaleMessage,
						'maleToMyself' => $this->maleMessageToMyself,
						'femaleToMyself' => $this->femaleMessageToMyself,
						'memberGenderErrorMessage' => $this->memberGenderErrorMessage
					),
					JSON_UNESCAPED_UNICODE
				);

				// Парсинг переменных
				$parsing_vars = array("FROM_USERNAME", "MEMBER_USERNAME", "MEMBER_USERNAME_GEN", "MEMBER_USERNAME_DAT", "MEMBER_USERNAME_ACC", "MEMBER_USERNAME_INS", "MEMBER_USERNAME_ABL");
				if ($this->allowDescription)
					$parsing_vars[] = "DESCRIPTION_MSG";
				$messagesJson = vk_parse_vars($messagesJson, $parsing_vars);

				if ($this->permittedMemberGender != 0)
					$permittedMemberGender_VKScript = "if(member.sex != {$this->permittedMemberGender}){API.messages.send({'peer_id':{$this->data->object->peer_id},'message':messages.memberGenderErrorMessage});return{'result':false};}";
				else
					$permittedMemberGender_VKScript = "";

				$res = (object) json_decode(vk_execute($messagesModule->buildVKSciptAppealByID($this->data->object->from_id) . "var users=API.users.get({'user_ids':[{$member_id},{$this->data->object->from_id}],'fields':'sex,screen_name,first_name_gen,first_name_dat,first_name_acc,first_name_ins,first_name_abl,last_name_gen,last_name_dat,last_name_acc,last_name_ins,last_name_abl'});var members=API.messages.getConversationMembers({'peer_id':{$this->data->object->peer_id}});var from_user=users[1];var member=users[0];if({$member_id}=={$this->data->object->from_id}){from_user=users[0];}var isContinue=false;var i=0;while(i<members.profiles.length){if(members.profiles[i].id=={$member_id}){isContinue=true;}i=i+1;}if(!isContinue){API.messages.send({'peer_id':{$this->data->object->peer_id},'message':appeal+', ❗указанного человека нет в беседе!'});return{'result':false};}var FROM_USERNAME='@'+from_user.screen_name+' ('+from_user.first_name.substr(0,2)+'. '+from_user.last_name+')';var MEMBER_USERNAME='@'+member.screen_name+' ('+member.first_name.substr(0,2)+'. '+member.last_name+')';var MEMBER_USERNAME_GEN='@'+member.screen_name+' ('+member.first_name_gen.substr(0,2)+'. '+member.last_name_gen+')';var MEMBER_USERNAME_DAT='@'+member.screen_name+' ('+member.first_name_dat.substr(0,2)+'. '+member.last_name_dat+')';var MEMBER_USERNAME_ACC='@'+member.screen_name+' ('+member.first_name_acc.substr(0,2)+'. '+member.last_name_acc+')';var MEMBER_USERNAME_INS='@'+member.screen_name+' ('+member.first_name_ins.substr(0,2)+'. '+member.last_name_ins+')';var MEMBER_USERNAME_ABL='@'+member.screen_name+' ('+member.first_name_abl.substr(0,2)+'. '+member.last_name_abl+')';{$descriptionMessage_VKScript}var messages={$messagesJson};{$permittedMemberGender_VKScript}var msg='';if({$member_id}=={$this->data->object->from_id}){if(member.sex==1){msg=messages.femaleToMyself;}else{msg=messages.maleToMyself;}}else{if(from_user.sex==1){msg=messages.female;}else{msg=messages.male;};};API.messages.send({'peer_id':{$this->data->object->peer_id},'message':msg});return{'result':true,'member_id':member.id};"))->response;
				if ($res->result)
					return $res->member_id;
				else
					return false;
			} else {
				if (isset($this->maleMessageToAll, $this->femaleMessageToAll) && array_search(mb_strtolower($argv[0]), array('все', 'всех', 'всем', 'всеми')) !== false) {
					// Выполнение действия над всеми
					$messagesJson = json_encode(array(
						'male' => $this->maleMessageToAll,
						'female' => $this->femaleMessageToAll
					), JSON_UNESCAPED_UNICODE);
					$parsing_vars = array("FROM_USERNAME", "DESCRIPTION_MSG");
					if ($this->allowDescription)
						$parsing_vars[] = "DESCRIPTION_MSG";
					$messagesJson = vk_parse_vars($messagesJson, $parsing_vars);
					$res = json_decode(vk_execute($messagesModule->buildVKSciptAppealByID($this->data->object->from_id) . "var from_user=API.users.get({'user_ids':[{$this->data->object->from_id}],'fields':'sex,screen_name'})[0];var FROM_USERNAME='@'+from_user.screen_name+' ('+from_user.first_name.substr(0,2)+'. '+from_user.last_name+')';{$descriptionMessage_VKScript}var messages={$messagesJson};var msg='';if(from_user.sex==1){msg=messages.female;}else{msg=messages.male;};API.messages.send({'peer_id':{$this->data->object->peer_id},'message':msg});return {'result':true,'member_id':0};"))->response;
					if ($res->result)
						return $res->member_id;
					else
						return false;
				}

				$messagesJson = json_encode(
					array(
						'male' => $this->maleMessage,
						'female' => $this->femaleMessage,
						'maleToMyself' => $this->maleMessageToMyself,
						'femaleToMyself' => $this->femaleMessageToMyself,
						'memberGenderErrorMessage' => $this->memberGenderErrorMessage
					),
					JSON_UNESCAPED_UNICODE
				);

				// Парсинг переменных
				$parsing_vars = array("FROM_USERNAME", "MEMBER_USERNAME", "MEMBER_USERNAME_GEN", "MEMBER_USERNAME_DAT", "MEMBER_USERNAME_ACC", "MEMBER_USERNAME_INS", "MEMBER_USERNAME_ABL");
				if ($this->allowDescription)
					$parsing_vars[] = "DESCRIPTION_MSG";
				$messagesJson = vk_parse_vars($messagesJson, $parsing_vars);

				if ($this->permittedMemberGender != 0)
					$permittedMemberGender_VKScript = "if(member.sex != {$this->permittedMemberGender}){API.messages.send({'peer_id':{$this->data->object->peer_id},'message':messages.memberGenderErrorMessage});return {'result':false};}";
				else
					$permittedMemberGender_VKScript = "";

				$user_info_words = explode(" ", $argv[0]);
				$word = array();
				for ($i = 0; $i < 2; $i++) {
					if (array_key_exists($i, $user_info_words)) {
						$first_letter = mb_strtoupper(mb_substr($user_info_words[$i], 0, 1));
						$other_letters = mb_strtolower(mb_substr($user_info_words[$i], 1));
						$word[$i] = "{$first_letter}{$other_letters}";
					} else
						$word[$i] = "";
				}

				$res = json_decode(vk_execute($messagesModule->buildVKSciptAppealByID($this->data->object->from_id) . "var members=API.messages.getConversationMembers({'peer_id':{$this->data->object->peer_id},'fields':'sex,screen_name,first_name_gen,first_name_dat,first_name_acc,first_name_ins,first_name_abl,last_name_gen,last_name_dat,last_name_acc,last_name_ins,last_name_abl'});var from_user= API.users.get({'user_ids':[{$this->data->object->from_id}],'fields':'sex,screen_name'})[0];var word1='{$word[0]}';var word2='{$word[1]}';var member_index=-1;var i=0;while(i<members.profiles.length){if(members.profiles[i].first_name==word1){if(word2==''){member_index=i;i=members.profiles.length;}else if(members.profiles[i].last_name==word2){member_index=i;i=members.profiles.length;}}else if(members.profiles[i].last_name==word1){member_index=i;i=members.profiles.length;}i=i+1;};if(member_index==-1){API.messages.send({'peer_id':{$this->data->object->peer_id},'message':appeal+', ❗указанного человека нет в беседе!'});return{'result':false};}var member = members.profiles[member_index];var FROM_USERNAME='@'+from_user.screen_name+' ('+from_user.first_name.substr(0, 2)+'. '+from_user.last_name+')';var MEMBER_USERNAME='@'+member.screen_name+' ('+member.first_name.substr(0,2)+'. '+member.last_name+')';var MEMBER_USERNAME_GEN='@'+member.screen_name+' ('+member.first_name_gen.substr(0,2)+'. '+member.last_name_gen+')';var MEMBER_USERNAME_DAT='@'+member.screen_name+' ('+member.first_name_dat.substr(0,2)+'. '+member.last_name_dat+')';var MEMBER_USERNAME_ACC='@'+member.screen_name+' ('+member.first_name_acc.substr(0,2)+'. '+member.last_name_acc+')';var MEMBER_USERNAME_INS='@'+member.screen_name+' ('+member.first_name_ins.substr(0,2)+'. '+member.last_name_ins+')';var MEMBER_USERNAME_ABL='@'+member.screen_name+' ('+member.first_name_abl.substr(0,2)+'. '+member.last_name_abl+')';{$descriptionMessage_VKScript}var messages={$messagesJson};{$permittedMemberGender_VKScript}var msg='';if(member.id=={$this->data->object->from_id}){if(member.sex==1){msg=messages.femaleToMyself;}else{msg=messages.maleToMyself;}}else{if(from_user.sex==1){msg=messages.female;}else{msg=messages.male;};};API.messages.send({'peer_id':{$this->data->object->peer_id},'message':msg});return{'result':true,'member_id':member.id};"))->response;
				if ($res->result)
					return $res->member_id;
				else
					return false;
			}
		}
	}
}

namespace {
	// Инициализация команд
	function roleplay_initcmd(&$event)
	{
		$event->addTextMessageCommand("!me", 'roleplay_me');
		$event->addTextMessageCommand("!do", 'roleplay_do');
		$event->addTextMessageCommand("!try", 'roleplay_try');
		$event->addTextMessageCommand("!s", 'roleplay_shout');
		$event->addTextMessageCommand("обнять", 'roleplay_hug');
		$event->addTextMessageCommand("поцеловать", 'roleplay_kiss');
	}

	///////////////////////////////////////////////////////////
	/// Handlers

	function roleplay_me($finput)
	{
		// Инициализация базовых переменных
		$data = $finput->data;
		$argv = $finput->argv;
		$db = $finput->db;

		if (is_null($argv[1])) {
			$botModule = new botModule($db);
			$msg = ", используйте \\\"!me <действие>\\\".";
			vk_execute($botModule->buildVKSciptAppealByID($data->object->from_id) . "
				return API.messages.send({'peer_id':{$data->object->peer_id},'message':appeal+'{$msg}'});
				");
		} else {
			$act = bot_get_text_by_argv($argv, 1);
			if (mb_substr($act, mb_strlen($act) - 1, mb_strlen($act) - 1) != ".") {
				$act = $act . ".";
			}
			vk_execute("
				var user = API.users.get({'user_ids':[{$data->object->from_id}],'fields':'screen_name'})[0];
				var msg = '@'+user.screen_name+' ('+user.first_name+' '+user.last_name+') '+'{$act}';
				return API.messages.send({'peer_id':{$data->object->peer_id},'message':msg});
				");
		}
	}

	function roleplay_try($finput)
	{
		// Инициализация базовых переменных
		$data = $finput->data;
		$argv = $finput->argv;
		$db = $finput->db;

		if (is_null($argv[1])) {
			$botModule = new botModule($db);
			$msg = ", используйте \\\"!try <действие>\\\".";
			vk_execute($botModule->buildVKSciptAppealByID($data->object->from_id) . "
				return API.messages.send({'peer_id':{$data->object->peer_id},'message':appeal+'{$msg}'});
				");
		} else {
			$act = bot_get_text_by_argv($argv, 1);
			if (mb_substr($act, mb_strlen($act) - 1, mb_strlen($act) - 1) != ".") {
				$act = $act . ".";
			}
			$random_number = mt_rand(0, 65535);
			if ($random_number % 2 == 1) {
				$act = $act . " (Неудачно)";
			} else {
				$act = $act . " (Удачно)";
			}
			vk_execute("
				var user = API.users.get({'user_ids':[{$data->object->from_id}],'fields':'screen_name'})[0];
				var msg = '@'+user.screen_name+' ('+user.first_name+' '+user.last_name+') '+'{$act}';
				return API.messages.send({'peer_id':{$data->object->peer_id},'message':msg});
				");
		}
	}

	function roleplay_do($finput)
	{
		// Инициализация базовых переменных
		$data = $finput->data;
		$argv = $finput->argv;
		$db = $finput->db;

		if (is_null($argv[1])) {
			$botModule = new botModule($db);
			$msg = ", используйте \\\"!do <действие>\\\".";
			vk_execute($botModule->buildVKSciptAppealByID($data->object->from_id) . "
				return API.messages.send({'peer_id':{$data->object->peer_id},'message':appeal+'{$msg}'});
				");
		} else {
			$act = bot_get_text_by_argv($argv, 1);
			$act = mb_strtoupper(mb_substr($act, 0, 1)) . mb_substr($act, 1, mb_strlen($act) - 1);
			if (mb_substr($act, mb_strlen($act) - 1, mb_strlen($act) - 1) != ".") {
				$act = $act . ".";
			}
			vk_execute("
				var user = API.users.get({'user_ids':[{$data->object->from_id}],'fields':'screen_name'})[0];
				var msg = '{$act} (( @'+user.screen_name+' ('+user.first_name+' '+user.last_name+') ))';
				return API.messages.send({'peer_id':{$data->object->peer_id},'message':msg});
				");
		}
	}

	function roleplay_shout($finput)
	{
		// Инициализация базовых переменных
		$data = $finput->data;
		$argv = $finput->argv;
		$db = $finput->db;

		if (is_null($argv[1])) {
			$botModule = new botModule($db);
			$msg = ", используйте \\\"!s <текст>\\\".";
			vk_execute($botModule->buildVKSciptAppealByID($data->object->from_id) . "
				return API.messages.send({'peer_id':{$data->object->peer_id},'message':appeal+'{$msg}'});
				");
		} else {
			$text = bot_get_text_by_argv($argv, 1);
			$vowels_letters = array('а', 'о', 'и', 'е', 'ё', 'э', 'ы', 'у', 'ю', 'я'/*, 'a', 'e', 'i', 'o', 'u'*/);
			$new_text = "";
			$symbols = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
			for ($i = 0; $i < sizeof($symbols); $i++) {
				$letter = "";
				for ($j = 0; $j < sizeof($vowels_letters); $j++) {
					if (mb_strtolower($symbols[$i]) == $vowels_letters[$j]) {
						$letter = $symbols[$i];
						break;
					}
				}
				if ($letter != "") {
					$random_number = mt_rand(3, 10);
					for ($j = 0; $j < $random_number; $j++) {
						$new_text = $new_text . $letter;
					}
				} else {
					$new_text = $new_text . $symbols[$i];
				}
			}
			$text = $new_text;
			if (mb_substr($text, mb_strlen($text) - 1, mb_strlen($text) - 1) != ".") {
				$text = $text . ".";
			}
			vk_execute("
				var user = API.users.get({'user_ids':[{$data->object->from_id}],'fields':'screen_name,sex'})[0];
				var shout_text = 'крикнул';
				if(user.sex == 1){
					shout_text = 'крикнула';
				}
				var msg = '@'+user.screen_name+' ('+user.first_name+' '+user.last_name+') '+shout_text+': {$text}';
				return API.messages.send({'peer_id':{$data->object->peer_id},'message':msg});
				");
		}
	}

	function roleplay_hug($finput)
	{
		// Инициализация базовых переменных
		$data = $finput->data;
		$argv = $finput->argv;
		$db = $finput->db;

		// Проверка режима
		$chatModes = $finput->event->getChatModes();
		if (!$chatModes->getModeValue("roleplay_enabled")) { // Проверка режима
			$messagesModule = new Bot\Messages($db);
			$messagesModule->setAppealID($data->object->from_id);
			$messagesModule->sendSilentMessage($data->object->peer_id, "%appeal%, ⛔Roleplay-команды отключены в беседе.");
			return;
		}

		$handler = new Roleplay\ActWithHandler($db, $data, $argv, "Обнять");
		$handler->maleMessage = "%FROM_USERNAME% обнял %MEMBER_USERNAME_ACC%.🤗";
		$handler->femaleMessage = "%FROM_USERNAME% обняла %MEMBER_USERNAME_ACC%.🤗";
		$handler->maleMessageToMyself = "%FROM_USERNAME% обнял сам себя.🤗";
		$handler->femaleMessageToMyself = "%FROM_USERNAME% обняла сама себя.🤗";
		$handler->maleMessageToAll = "%FROM_USERNAME% обнял всех.🤗";
		$handler->femaleMessageToAll = "%FROM_USERNAME% обняла всех.🤗";

		$handler->handle();
	}

	function roleplay_kiss($finput)
	{
		// Инициализация базовых переменных
		$data = $finput->data;
		$argv = $finput->argv;
		$db = $finput->db;

		// Проверка режима
		$chatModes = $finput->event->getChatModes();
		if (!$chatModes->getModeValue("roleplay_enabled")) { // Проверка режима
			$messagesModule = new Bot\Messages($db);
			$messagesModule->setAppealID($data->object->from_id);
			$messagesModule->sendSilentMessage($data->object->peer_id, "%appeal%, ⛔Roleplay-команды отключены в беседе.");
			return;
		}

		$handler = new Roleplay\ActWithHandler($db, $data, $argv, "Поцеловать");
		$handler->maleMessage = "%FROM_USERNAME% поцеловал %MEMBER_USERNAME_ACC%.😘";
		$handler->femaleMessage = "%FROM_USERNAME% поцеловала %MEMBER_USERNAME_ACC%.😘";
		$handler->maleMessageToMyself = "%FROM_USERNAME% поцеловал сам себя.😘";
		$handler->femaleMessageToMyself = "%FROM_USERNAME% поцеловала сама себя.😘";
		$handler->maleMessageToAll = "%FROM_USERNAME% поцеловал всех.😘";
		$handler->femaleMessageToAll = "%FROM_USERNAME% поцеловала всех.😘";

		$handler->handle();
	}

	function roleplay_shakehand($finput)
	{
		// Инициализация базовых переменных
		$data = $finput->data;
		$argv = $finput->argv;
		$db = $finput->db;

		// Проверка режима
		$chatModes = $finput->event->getChatModes();
		if (!$chatModes->getModeValue("roleplay_enabled")) { // Проверка режима
			$messagesModule = new Bot\Messages($db);
			$messagesModule->setAppealID($data->object->from_id);
			$messagesModule->sendSilentMessage($data->object->peer_id, "%appeal%, ⛔Roleplay-команды отключены в беседе.");
			return;
		}

		$handler = new Roleplay\ActWithHandler($db, $data, $argv, "Пожать руку");
		$handler->maleMessage = "%FROM_USERNAME% пожал руку %MEMBER_USERNAME_DAT%.🤝🏻";
		$handler->femaleMessage = "%FROM_USERNAME% пожала руку %MEMBER_USERNAME_DAT%.🤝🏻";
		$handler->maleMessageToMyself = "%FROM_USERNAME% настолько ЧСВ, что пожал руку сам с себе.🤝🏻";
		$handler->femaleMessageToMyself = "%FROM_USERNAME% настолько ЧСВ, что пожала руку сама с себе.🤝🏻";
		$handler->maleMessageToAll = "%FROM_USERNAME% пожал руку всем.🤝🏻";
		$handler->femaleMessageToAll = "%FROM_USERNAME% пожала руку всем.🤝🏻";

		$handler->handle();
	}

	function roleplay_highfive($finput)
	{
		// Инициализация базовых переменных
		$data = $finput->data;
		$argv = $finput->argv;
		$db = $finput->db;

		// Проверка режима
		$chatModes = $finput->event->getChatModes();
		if (!$chatModes->getModeValue("roleplay_enabled")) { // Проверка режима
			$messagesModule = new Bot\Messages($db);
			$messagesModule->setAppealID($data->object->from_id);
			$messagesModule->sendSilentMessage($data->object->peer_id, "%appeal%, ⛔Roleplay-команды отключены в беседе.");
			return;
		}

		$handler = new Roleplay\ActWithHandler($db, $data, $argv, "Дать пять");
		$handler->maleMessage = "%FROM_USERNAME% дал пять %MEMBER_USERNAME_DAT%.👋🏻";
		$handler->femaleMessage = "%FROM_USERNAME% дала пять %MEMBER_USERNAME_DAT%.👋🏻";
		$handler->maleMessageToMyself = "%FROM_USERNAME% дал пять себе.👋🏻";
		$handler->femaleMessageToMyself = "%FROM_USERNAME% дала пять себе.👋🏻";
		$handler->maleMessageToAll = "%FROM_USERNAME% дал пять всем.👋🏻";
		$handler->femaleMessageToAll = "%FROM_USERNAME% дала пять всем.👋🏻";

		$handler->handle();
	}
}
