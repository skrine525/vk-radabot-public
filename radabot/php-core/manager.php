<?php

/////////////////////////////////////////////
/// API

// Permission API
class PermissionSystem
{
	// Список всех режимов.
	private static $permission_list = [];

	private $db;
	private $owner_id;

	function __construct($db)
	{
		$this->db = $db;

		$query = new MongoDB\Driver\Query(['_id' => $this->db->getDocumentID()], ['projection' => ["_id" => 0, "owner_id" => 1]]);
		$extractor = $this->db->executeQuery($query);
		$this->owner_id = $extractor->getValue('0.owner_id');
	}

	public static function getPermissionList(){
		if(count(self::$permission_list) == 0){
			$data = json_decode(file_get_contents(BOTPATH_MANAGERDATAFILE), true);
			if ($data === false) {
				error_log('Unable to read manager.json file. File not exists or invalid.');
				exit;
			}
			foreach ($data["user_permissions"] as $key => $value) {
				$type = 0;
				if($value['hidden'])
					$type = 2;
				if($value['default'])
					$type++;
				self::$permission_list[$key] = [
					'label' => $value['label'],
					'type' => $type
				];
			}
		}

		return self::$permission_list;
	}

	public function getChatOwnerID()
	{
		return $this->owner_id;
	}

	public function isPermissionExists(string $permission_id)
	{
		return array_key_exists($permission_id, self::getPermissionList());
	}

	public function getUserPermissions(int $user_id)
	{
		$permissions = [];
		if ($user_id == $this->owner_id) {
			$query = new MongoDB\Driver\Query(['_id' => $this->db->getDocumentID()], ['projection' => ["_id" => 0, "chat_settings.user_permissions.id{$user_id}" => 1]]);
			$extractor = $this->db->executeQuery($query);
			$db_permissions = $extractor->getValue([0, 'chat_settings', 'user_permissions', "id{$user_id}"], []);
			foreach (self::getPermissionList() as $key => $value) {
				if ($value['type'] == 0 || $value['type'] == 1)
					$permissions[] = $key;
				elseif (array_key_exists($key, $db_permissions) && $db_permissions->$key)
					$permissions[] = $key;
				elseif ($value['type'] == 3)
					$permissions[] = $key;
			}
		} else {
			$query = new MongoDB\Driver\Query(['_id' => $this->db->getDocumentID()], ['projection' => ["_id" => 0, "chat_settings.user_permissions.id{$user_id}" => 1]]);
			$extractor = $this->db->executeQuery($query);
			$db_permissions = $extractor->getValue([0, 'chat_settings', 'user_permissions', "id{$user_id}"], []);
			foreach (self::getPermissionList() as $key => $value) {
				if (array_key_exists($key, $db_permissions) && $db_permissions->$key)
					$permissions[] = $key;
				elseif ($value['type'] == 1 || $value['type'] == 3)
					$permissions[] = $key;
			}
		}
		return $permissions;
	}

	public function checkUserPermission(int $user_id, string $permission_id)
	{
		if (!$this->isPermissionExists($permission_id))
			return null;

		if ($user_id == $this->owner_id) {
			if (self::getPermissionList()[$permission_id]['type'] == 2)
				$default_state = false;
			elseif (self::getPermissionList()[$permission_id]['type'] == 0 || self::getPermissionList()[$permission_id]['type'] == 1 || self::getPermissionList()[$permission_id]['type'] == 3)
				$default_state = true;
		} else {
			if (self::getPermissionList()[$permission_id]['type'] == 0 || self::getPermissionList()[$permission_id]['type'] == 2)
				$default_state = false;
			elseif (self::getPermissionList()[$permission_id]['type'] == 1 || self::getPermissionList()[$permission_id]['type'] == 3)
				$default_state = true;
		}

		$query = new MongoDB\Driver\Query(['_id' => $this->db->getDocumentID()], ['projection' => ["_id" => 0, "chat_settings.user_permissions.id{$user_id}" => 1]]);
		$extractor = $this->db->executeQuery($query);
		return $extractor->getValue([0, 'chat_settings', 'user_permissions', "id{$user_id}", $permission_id], $default_state);
	}

	public function addUserPermission(int $user_id, string $permission_id)
	{
		// Проверка на владельца и существования разрешения
		if (!$this->isPermissionExists($permission_id) || $user_id <= 0 || ($user_id == $this->owner_id && (self::getPermissionList()[$permission_id]['type'] == 0 || self::getPermissionList()[$permission_id]['type'] == 1)))
			return false;

		if (!$this->checkUserPermission($user_id, $permission_id)) {
			$bulk = new MongoDB\Driver\BulkWrite;
			if (self::getPermissionList()[$permission_id]['type'] == 0 || self::getPermissionList()[$permission_id]['type'] == 2)
				$bulk->update(['_id' => $this->db->getDocumentID()], ['$set' => ["chat_settings.user_permissions.id{$user_id}.{$permission_id}" => true]]);
			elseif (self::getPermissionList()[$permission_id]['type'] == 1 || self::getPermissionList()[$permission_id]['type'] == 3)
				$bulk->update(['_id' => $this->db->getDocumentID()], ['$unset' => ["chat_settings.user_permissions.id{$user_id}.{$permission_id}" => 0]]);

			$this->db->executeBulkWrite($bulk);
			return true;
		} else
			return false;
	}

	public function deleteUserPermission($user_id, $permission_id)
	{
		// Проверка на владельца и существования разрешения
		if (!$this->isPermissionExists($permission_id) || $user_id <= 0 || ($user_id == $this->owner_id && (self::getPermissionList()[$permission_id]['type'] == 0 || self::getPermissionList()[$permission_id]['type'] == 1)))
			return false;

		if ($this->checkUserPermission($user_id, $permission_id)) {
			$bulk = new MongoDB\Driver\BulkWrite;
			if (self::getPermissionList()[$permission_id]['type'] == 0 || self::getPermissionList()[$permission_id]['type'] == 2)
				$bulk->update(['_id' => $this->db->getDocumentID()], ['$unset' => ["chat_settings.user_permissions.id{$user_id}.{$permission_id}" => 0]]);
			elseif (self::getPermissionList()[$permission_id]['type'] == 1 || self::getPermissionList()[$permission_id]['type'] == 3)
				$bulk->update(['_id' => $this->db->getDocumentID()], ['$set' => ["chat_settings.user_permissions.id{$user_id}.{$permission_id}" => false]]);

			$this->db->executeBulkWrite($bulk);
			return true;
		} else
			return false;
	}
}

class ChatModes
{
	private static $mode_list = [];

	private $db;
	private $modes;

	public static function getDefaultModeList(){
		if(count(self::$mode_list) == 0){
			$data = json_decode(file_get_contents(BOTPATH_MANAGERDATAFILE), true);
			if ($data === false) {
				error_log('Unable to read manager.json file. File not exists or invalid.');
				exit;
			}
			foreach ($data["chat_modes"] as $key => $value) {
				self::$mode_list[$key] = [
					'label' => $value['label'],
					'default_state' => $value['default']
				];
			}
		}

		return self::$mode_list;
	}

	function __construct($db)
	{
		if (is_null($db))
			return false;
		else {
			$this->db = $db;

			$query = new MongoDB\Driver\Query(['_id' => $this->db->getDocumentID()], ['projection' => ["_id" => 0, "chat_settings.chat_modes" => 1]]);
			$extractor = $this->db->executeQuery($query);
			$db_modes = $extractor->getValue("0.chat_settings.chat_modes", []);

			$this->modes = array();
			foreach (self::getDefaultModeList() as $key => $value) {
				if (array_key_exists($key, $db_modes))
					$this->modes[$key] = $db_modes->$key;
				else
					$this->modes[$key] = $value["default_state"];
			}
		}
	}

	public function getModeLabel($name)
	{
		if (gettype($name) != "string" || !array_key_exists($name, self::getDefaultModeList()))
			return null;

		return self::getDefaultModeList()[$name]["label"];
	}

	public function getModeValue($name)
	{
		if (gettype($name) != "string" || !array_key_exists($name, self::getDefaultModeList()))
			return null;

		$query = new MongoDB\Driver\Query(['_id' => $this->db->getDocumentID()], ['projection' => ["_id" => 0, "chat_settings.chat_modes.{$name}" => 1]]);
		$extractor = $this->db->executeQuery($query);
		return $extractor->getValue([0, 'chat_settings', 'chat_modes', $name], self::getDefaultModeList()[$name]["default_state"]);
	}

	public function setModeValue($name, $value)
	{
		if (gettype($name) != "string" || gettype($value) != "boolean" || !array_key_exists($name, self::getDefaultModeList()))
			return false;

		$bulk = new MongoDB\Driver\BulkWrite;
		if ($value === self::getDefaultModeList()[$name]["default_state"])
			$bulk->update(['_id' => $this->db->getDocumentID()], ['$unset' => ["chat_settings.chat_modes.{$name}" => 0]]);
		else
			$bulk->update(['_id' => $this->db->getDocumentID()], ['$set' => ["chat_settings.chat_modes.{$name}" => $value]]);

		$this->db->executeBulkWrite($bulk);
		return true;
	}

	public function getModeList()
	{
		$query = new MongoDB\Driver\Query(['_id' => $this->db->getDocumentID()], ['projection' => ["_id" => 0, "chat_settings.chat_modes" => 1]]);
		$extractor = $this->db->executeQuery($query);
		$db_modes = $extractor->getValue("0.chat_settings.chat_modes", []);

		$list = array();
		foreach (self::getDefaultModeList() as $key => $value) {
			if (array_key_exists($key, $db_modes))
				$mode_value = $db_modes->$key;
			else
				$mode_value = $value["default_state"];

			$list[] = array(
				'name' => $key,
				'label' => $value["label"],
				'value' => $mode_value
			);
		}
		return $list;
	}
}

class BanSystem
{
	public static function getBanList($db)
	{
		$query = new MongoDB\Driver\Query(['_id' => $db->getDocumentID()], ['projection' => ['_id' => 0, "chat_settings.banned_users" => 1]]);
		$extractor = $db->executeQuery($query);
		$banned_users = $extractor->getValue([0, 'chat_settings', 'banned_users'], []);
		$ban_list = [];
		foreach ($banned_users as $user) {
			$ban_list[] = $user;
		}
		return $ban_list;
	}

	public static function getUserBanInfo($db, $user_id)
	{
		$query = new MongoDB\Driver\Query(['_id' => $db->getDocumentID()], ['projection' => ['_id' => 0, "chat_settings.banned_users.id{$user_id}" => 1]]);
		$extractor = $db->executeQuery($query);
		$ban_info = $extractor->getValue([0, 'chat_settings', 'banned_users', "id{$user_id}"], false);
		return $ban_info;
	}

	public static function banUser($db, $user_id, $reason, $banned_by, $time)
	{
		if (BanSystem::getUserBanInfo($db, $user_id) !== false)
			return false;
		else {
			$ban_info = array(
				'user_id' => intval($user_id),
				'reason' => $reason,
				'banned_by' => $banned_by,
				'time' => $time
			);
			$bulk = new MongoDB\Driver\BulkWrite;
			$bulk->update(['_id' => $db->getDocumentID()], ['$set' => ["chat_settings.banned_users.id{$user_id}" => $ban_info]]);
			$db->executeBulkWrite($bulk);
			return true;
		}
	}

	public static function unbanUser($db, $user_id)
	{
		if (BanSystem::getUserBanInfo($db, $user_id) !== false) {
			$bulk = new MongoDB\Driver\BulkWrite;
			$bulk->update(['_id' => $db->getDocumentID()], ['$unset' => ["chat_settings.banned_users.id{$user_id}" => 0]]);
			$db->executeBulkWrite($bulk);
			return true;
		} else
			return false;
	}
}

class AntiFlood
{
	private $db;

	const TIME_INTERVAL = 10; 				// Промежуток времени в секундах
	const MSG_COUNT_MAX = 5; 				// Максимальное количество сообщений в промежуток времени
	const MSG_LENGTH_MAX = 2048; 			// Максимальная длинна сообщения

	function __construct($db)
	{
		$this->db = $db;
	}

	public function checkMember($data)
	{
		$date = $data->object->date;
		$member_id = $data->object->from_id;
		$text = $data->object->text;

		if (mb_strlen($text) > self::MSG_LENGTH_MAX) // Ограничение на длинну сообщения
			return true;

		$query = new MongoDB\Driver\Query(['_id' => $this->db->getDocumentID()], ['projection' => ['_id' => 0, "member{$member_id}" => 1]]);
		$extractor = $this->db->executeQuery($query, 'antiflood');
		$user_data = (array) $extractor->getValue([0, "member{$member_id}"], []);

		// Ограничение на частоту сообщений
		foreach ($user_data as $key => $value) {
			if ($date - $value >= AntiFlood::TIME_INTERVAL)
				unset($user_data[$key]);
		}
		$user_data = array_filter($user_data);
		$user_data[] = $date;
		// Сохранение новых данных
		$bulk = new MongoDB\Driver\BulkWrite;
		$bulk->update(['_id' => $this->db->getDocumentID()], ['$set' => ["member{$member_id}" => $user_data]], ['upsert' => true]);
		$this->db->executeBulkWrite($bulk, 'antiflood');

		if (count($user_data) > AntiFlood::MSG_COUNT_MAX)
			return true;
		else
			return false;
	}

	public static function handler($data, $db, $chatModes, $permissionSystem)
	{
		if (!$chatModes->getModeValue('antiflood_enabled'))
			return false;

		$returnValue = false;
		$floodSystem = new AntiFlood($db);
		if ($floodSystem->checkMember($data)) {
			$messagesModule = new Bot\Messages($db);

			if ($permissionSystem->checkUserPermission($data->object->from_id, 'prohibit_antiflood')) // Проверка разрешения
				return false;

			$r = json_decode(vk_execute($messagesModule->buildVKSciptAppealByID($data->object->from_id) . "var peer_id={$data->object->peer_id};var member_id={$data->object->from_id};var user=API.users.get({'user_ids':member_id})[0];var members=API.messages.getConversationMembers({'peer_id':peer_id});var user_index=-1;var i=0;while(i<members.items.length){if(members.items[i].member_id==user.id){user_index=i;i=members.items.length;};i=i+1;};if(!members.items[user_index].is_admin&&user_index!=-1){var msg='Пользователь '+appeal+' был кикнут. Причина: Флуд.';API.messages.send({'peer_id':peer_id,'message':msg});API.messages.removeChatUser({'chat_id':peer_id-2000000000,'member_id':user.id});return true;}return false;"));

			if (gettype($r) == "object" && property_exists($r, 'response'))
				$returnValue = $r->response;
		}
		return $returnValue;
	}
}

/////////////////////////////////////////////
/// Handlers

function manager_initcmd($event)
{
	// Управление беседой
	$event->addTextMessageCommand("!онлайн", 'manager_online_list');
	$event->addTextMessageCommand("!ban", 'manager_ban_user');
	$event->addTextMessageCommand("!unban", 'manager_unban_user');
	$event->addTextMessageCommand("!baninfo", 'manager_baninfo_user');
	$event->addTextMessageCommand("!banlist", 'manager_banlist_user');
	$event->addTextMessageCommand("!kick", 'manager_kick_user');
	$event->addTextMessageCommand("!ник", 'manager_nick');
	$event->addTextMessageCommand("!приветствие", 'manager_greeting');
	$event->addTextMessageCommand("!modes", "manager_mode_list");

	// Прочее
	$event->addTextMessageCommand("!ники", 'manager_show_nicknames');

	// Callback-кнопки
	$event->addCallbackButtonCommand("manager_mode", 'manager_mode_cpanel_cb');
}

function manager_mode_list($finput)
{
	// Инициализация базовых переменных
	$data = $finput->data;
	$argv = $finput->argv;
	$db = $finput->db;

	$messagesModule = new Bot\Messages($db);
	$messagesModule->setAppealID($data->object->from_id);
	$chatModes = $finput->event->getChatModes();

	if (array_key_exists(1, $argv))
		$list_number_from_word = intval($argv[1]);
	else
		$list_number_from_word = 1;

	/////////////////////////////////////////////////////
	////////////////////////////////////////////////////
	$list_in = $chatModes->getModeList(); // Входной список
	$list_out = array(); // Выходной список

	$list_number = $list_number_from_word; // Номер текущего списка
	$list_size = 10; // Размер списка
	////////////////////////////////////////////////////
	if (count($list_in) % $list_size == 0)
		$list_max_number = intdiv(count($list_in), $list_size);
	else
		$list_max_number = intdiv(count($list_in), $list_size) + 1;
	$list_min_index = ($list_size * $list_number) - $list_size;
	if ($list_size * $list_number >= count($list_in))
		$list_max_index = count($list_in) - 1;
	else
		$list_max_index = $list_size * $list_number - 1;
	if ($list_number <= $list_max_number && $list_number > 0) {
		// Обработчик списка
		for ($i = $list_min_index; $i <= $list_max_index; $i++) {
			$list_out[] = $list_in[$i];
		}
	} else {
		// Сообщение об ошибке
		$messagesModule->sendSilentMessage($data->object->peer_id, "%appeal%, ⛔указан неверный номер списка!");
		return;
	}

	$message = "%appeal%, список режимов беседы:";
	for ($i = 0; $i < count($list_out); $i++) {
		$name = $list_out[$i]["name"];
		$value = "true";
		if (!$list_out[$i]["value"])
			$value = "false";
		$message = $message . "\n• {$name} — {$value}";
	}

	$keyboard = vk_keyboard_inline(array(
		array(
			vk_callback_button("Режимы", array('manager_mode', $data->object->from_id), 'positive')
		)
	));

	$messagesModule->sendSilentMessage($data->object->peer_id, $message, array('keyboard' => $keyboard));
}

function manager_mode_cpanel_cb($finput)
{
	// Инициализация базовых переменных
	$data = $finput->data;
	$payload = $finput->payload;
	$db = $finput->db;

	$testing_user_id = bot_get_array_value($payload, 1, $data->object->user_id);
	if ($testing_user_id !== $data->object->user_id) {
		bot_show_snackbar($data->object->event_id, $data->object->user_id, $data->object->peer_id, '⛔ У вас нет доступа к этому меню!');
		return;
	}

	$message = "";
	$keyboard_buttons = array();

	$chatModes = $finput->event->getChatModes();

	$list_number = bot_get_array_value($payload, 2, 1);
	$mode_name = bot_get_array_value($payload, 3, false);

	if ($mode_name !== false) {
		$permissionSystem = $finput->event->getPermissionSystem();
		if (!$permissionSystem->checkUserPermission($data->object->user_id, 'customize_chat')) { // Проверка разрешения
			bot_show_snackbar($data->object->event_id, $data->object->user_id, $data->object->peer_id, "⛔ У вас нет прав для использования этой функции.");
			return;
		}
		if ($mode_name === 0) {
			bot_show_snackbar($data->object->event_id, $data->object->user_id, $data->object->peer_id, "⛔ Этот пустой элемент.");
			return;
		}
		$chatModes->setModeValue($mode_name, !$chatModes->getModeValue($mode_name));
	}

	$mode_list = $chatModes->getModeList();

	$list_size = 3;
	$listBuilder = new Bot\ListBuilder($mode_list, $list_size);
	$list = $listBuilder->build($list_number);

	if ($list->result) {
		$message = "%appeal%, Режимы беседы.";
		for ($i = 0; $i < $list_size; $i++) {
			if (array_key_exists($i, $list->list->out)) {
				if ($list->list->out[$i]["value"])
					$color = 'positive';
				else
					$color = 'negative';
				$keyboard_buttons[] = array(vk_callback_button($list->list->out[$i]["label"], array('manager_mode', $testing_user_id, $list_number, $list->list->out[$i]["name"]), $color));
			} else
				$keyboard_buttons[] = array(vk_callback_button("&#12288;", array('manager_mode', $testing_user_id, $list_number, 0), 'primary'));
		}

		if ($list->list->max_number > 1) {
			$list_buttons = array();
			if ($list->list->number != 1) {
				$previous_list = $list->list->number - 1;
				$emoji_str = bot_int_to_emoji_str($previous_list);
				$list_buttons[] = vk_callback_button("{$emoji_str} ⬅", array('manager_mode', $testing_user_id, $previous_list), 'secondary');
			}
			if ($list->list->number != $list->list->max_number) {
				$next_list = $list->list->number + 1;
				$emoji_str = bot_int_to_emoji_str($next_list);
				$list_buttons[] = vk_callback_button("➡ {$emoji_str}", array('manager_mode', $testing_user_id, $next_list), 'secondary');
			}
			$keyboard_buttons[] = $list_buttons;
		}
		$keyboard_buttons[] = array(
			vk_callback_button("Меню", array('bot_menu', $testing_user_id), "secondary"),
			vk_callback_button("Закрыть", array('bot_menu', $testing_user_id, 0), "negative")
		);
	} else
		bot_show_snackbar($data->object->event_id, $data->object->user_id, $data->object->peer_id, "⛔ Внутренняя ошибка: Неверный номер списка.");

	$messagesModule = new Bot\Messages($db);
	$messagesModule->setAppealID($data->object->user_id);
	$keyboard = vk_keyboard_inline($keyboard_buttons);
	$messagesModule->editMessage($data->object->peer_id, $data->object->conversation_message_id, $message, array('keyboard' => $keyboard));
}

function manager_ban_user($finput)
{
	// Инициализация базовых переменных
	$data = $finput->data;
	$argv = $finput->argv;
	$db = $finput->db;

	$permissionSystem = $finput->event->getPermissionSystem();
	$messagesModule = new Bot\Messages($db);
	$messagesModule->setAppealID($data->object->from_id);

	if (!$permissionSystem->checkUserPermission($data->object->from_id, 'manage_punishments')) { // Проверка разрешения
		$messagesModule->sendSilentMessage($data->object->peer_id, Bot\Messages::MESSAGE_NO_RIGHTS);
		return;
	}

	if (array_key_exists(0, $data->object->fwd_messages)) {
		$member_id = $data->object->fwd_messages[0]->from_id;
		$reason = bot_get_text_by_argv($argv, 1);
	} elseif (array_key_exists(1, $argv) && bot_get_userid_by_mention($argv[1], $member_id)) {
		$reason = bot_get_text_by_argv($argv, 2);
	} elseif (array_key_exists(1, $argv) && bot_get_userid_by_nick($db, $argv[1], $member_id)) {
		$reason = bot_get_text_by_argv($argv, 2);
	} elseif (array_key_exists(1, $argv) && is_numeric($argv[1])) {
		$member_id = intval($argv[1]);
		$reason = bot_get_text_by_argv($argv, 2);
	} else $member_id = 0;

	if ($member_id == 0) {
		$messagesModule->sendSilentMessage($data->object->peer_id, "%appeal%, используйте \"!ban <пользователь> <причина>\".");
		return;
	}

	if ($permissionSystem->checkUserPermission($member_id, 'manage_punishments')) {  // Проверка разрешения
		$messagesModule->sendSilentMessage($data->object->peer_id, "%appeal%, @id{$member_id} (Пользователя) нельзя забанить. Причина: Пользователь имеет специальное разрешение.");
		return;
	} elseif (BanSystem::getUserBanInfo($db, $member_id) !== false) {
		$messagesModule->sendSilentMessage($data->object->peer_id, "%appeal%, @id{$member_id} (Пользователя) нельзя забанить. Причина: Пользователь уже забанен.");
		return;
	}

	if ($reason == "")
		$reason = "Не указано";
	else {
		$reason = mb_eregi_replace("\n", " ", $reason);
	}

	$ban_info = json_encode(array("user_id" => $member_id, "reason" => $reason), JSON_UNESCAPED_UNICODE);

	$res = json_decode(vk_execute($messagesModule->buildVKSciptAppealByID($data->object->from_id) . "var peer_id={$data->object->peer_id};var ban_info={$ban_info};var users=API.users.get({'user_ids':[{$member_id}]});var members=API.messages.getConversationMembers({'peer_id':peer_id});var user=0;if(users.length > 0){user=users[0];}else{var msg=', указанного пользователя не существует.';API.messages.send({'peer_id':peer_id,'message':appeal+msg,'disable_mentions':true});return 'nioh';}var user_id=ban_info.user_id;var user_id_index=-1;var i=0;while(i<members.items.length){if(members.items[i].member_id == user_id){if(members.items[i].is_admin){var msg=', @id{$member_id} (Пользователя) нельзя забанить. Причина: Пользователь является администратором беседы.';API.messages.send({'peer_id':peer_id,'message':appeal+msg,'disable_mentions':true});return 'nioh';}};i=i+1;};var msg=appeal+', пользователь @id{$member_id} ('+user.first_name.substr(0, 2)+'. '+user.last_name+') был забанен.\\nПричина: '+ban_info.reason+'.';API.messages.send({'peer_id':peer_id,'message':msg});API.messages.removeChatUser({'chat_id':peer_id-2000000000,'member_id':user_id});return 'ok';"), false);
	if ($res->response == 'ok') {
		BanSystem::banUser($db, $member_id, $reason, $data->object->from_id, time());
	}
}

function manager_unban_user($finput)
{
	// Инициализация базовых переменных
	$data = $finput->data;
	$argv = $finput->argv;
	$db = $finput->db;

	$botModule = new BotModule($db);
	$permissionSystem = $finput->event->getPermissionSystem();

	if (!$permissionSystem->checkUserPermission($data->object->from_id, 'manage_punishments')) { // Проверка ранга (Президент)
		$botModule->sendSystemMsg_NoRights($data);
		return;
	}

	$member_ids = array();
	for ($i = 0; $i < sizeof($data->object->fwd_messages); $i++) {
		$isContinue = true;
		for ($j = 0; $j < sizeof($member_ids); $j++) {
			if ($member_ids[$j] == $data->object->fwd_messages[$i]->from_id) {
				$isContinue = false;
				break;
			}
		}
		if ($isContinue) {
			$member_ids[] = $data->object->fwd_messages[$i]->from_id;
		}
	}
	for ($i = 1; $i < sizeof($argv); $i++) {
		if (bot_get_userid_by_mention($argv[$i], $member_id)) {
			$isContinue = true;
			for ($j = 0; $j < sizeof($member_ids); $j++) {
				if ($member_ids[$j] == $member_id) {
					$isContinue = false;
					break;
				}
			}
			if ($isContinue) {
				$member_ids[] = $member_id;
			}
		} elseif (bot_get_userid_by_nick($db, $argv[$i], $member_id)) {
			$isContinue = true;
			for ($j = 0; $j < sizeof($member_ids); $j++) {
				if ($member_ids[$j] == $member_id) {
					$isContinue = false;
					break;
				}
			}
			if ($isContinue) {
				$member_ids[] = $member_id;
			}
		} elseif (is_numeric($argv[$i])) {
			$member_id = intval($argv[$i]);
			$isContinue = true;
			for ($j = 0; $j < sizeof($member_ids); $j++) {
				if ($member_ids[$j] == $member_id) {
					$isContinue = false;
					break;
				}
			}
			if ($isContinue) {
				$member_ids[] = $member_id;
			}
		}
	}

	if (sizeof($member_ids) == 0) {
		$msg = ", используйте \\\"!unban <упоминание/id>\\\" или перешлите сообщение с командой \\\"!unban\\\".";
		vk_execute($botModule->buildVKSciptAppealByID($data->object->from_id) . "
				return API.messages.send({'peer_id':{$data->object->peer_id},'message':appeal+'{$msg}','disable_mentions':true});
				");
		return;
	} else if (sizeof($member_ids) > 10) {
		$msg = ", нельзя разбанить более 10 участников одновременно.";
		vk_execute($botModule->buildVKSciptAppealByID($data->object->from_id) . "
				return API.messages.send({'peer_id':{$data->object->peer_id},'message':appeal+'{$msg}','disable_mentions':true});
				");
		return;
	}

	$unbanned_member_ids = array();

	$banned_users = BanSystem::getBanList($db);
	for ($i = 0; $i < sizeof($member_ids); $i++) {
		for ($j = 0; $j < sizeof($banned_users); $j++) {
			if ($member_ids[$i] == $banned_users[$j]->user_id) {
				$unbanned_member_ids[] = $banned_users[$j]->user_id;
			}
		}
	}
	$member_ids_exe_array = implode(',', $unbanned_member_ids);

	$res = json_decode(vk_execute($botModule->buildVKSciptAppealByID($data->object->from_id) . "
		var peer_id = {$data->object->peer_id};
		var member_ids = [{$member_ids_exe_array}];
		var users = API.users.get({'user_ids':member_ids});
		var banned_ids = [];

		var msg = ', следующие пользователи были разбанены:\\n';
		var msg_unbanned_users = '';

		var j = 0; while(j < users.length){
			var user_id = users[j].id;
			msg_unbanned_users = msg_unbanned_users + '✅@id'+ user_id + ' (' + users[j].first_name + ' ' + users[j].last_name + ')\\n';
			j = j + 1;
		};
		if(msg_unbanned_users != ''){
			API.messages.send({'peer_id':peer_id,'message':appeal+msg+msg_unbanned_users,'disable_mentions':true});
		} else {
			msg = ', ни один пользователь не был разбанен.';
			API.messages.send({'peer_id':peer_id,'message':appeal+msg,'disable_mentions':true});
		}

		return 'ok';
		"));

	if ($res->response == 'ok') {
		for ($i = 0; $i < sizeof($unbanned_member_ids); $i++) {
			for ($j = 0; $j < sizeof($banned_users); $j++) {
				if ($unbanned_member_ids[$i] == $banned_users[$j]->user_id) {
					BanSystem::unbanUser($db, $unbanned_member_ids[$i]);
				}
			}
		}
	}
}

function manager_banlist_user($finput)
{
	// Инициализация базовых переменных
	$data = $finput->data;
	$argv = $finput->argv;
	$db = $finput->db;

	$botModule = new BotModule($db);

	if (array_key_exists(1, $argv))
		$list_number_from_word = intval($argv[1]);
	else
		$list_number_from_word = 1;


	$banned_users = BanSystem::getBanList($db);
	if (sizeof($banned_users) == 0) {
		vk_execute($botModule->buildVKSciptAppealByID($data->object->from_id) . "return API.messages.send({'peer_id':{$data->object->peer_id},'message':appeal+', в беседе нет забаненных пользователей.','disable_mentions':true});");
		return;
	}

	/////////////////////////////////////////////////////
	////////////////////////////////////////////////////
	$list_in = &$banned_users; // Входной список
	$list_out = array(); // Выходной список

	$list_number = $list_number_from_word; // Номер текущего списка
	$list_size = 10; // Размер списка
	////////////////////////////////////////////////////
	if (count($list_in) % $list_size == 0)
		$list_max_number = intdiv(count($list_in), $list_size);
	else
		$list_max_number = intdiv(count($list_in), $list_size) + 1;
	$list_min_index = ($list_size * $list_number) - $list_size;
	if ($list_size * $list_number >= count($list_in))
		$list_max_index = count($list_in) - 1;
	else
		$list_max_index = $list_size * $list_number - 1;
	if ($list_number <= $list_max_number && $list_number > 0) {
		// Обработчик списка
		for ($i = $list_min_index; $i <= $list_max_index; $i++) {
			$list_out[] = $list_in[$i];
		}
	} else {
		// Сообщение об ошибке
		$botModule->sendSilentMessage($data->object->peer_id, ", ⛔указан неверный номер списка!", $data->object->from_id);
		return;
	}
	////////////////////////////////////////////////////
	////////////////////////////////////////////////////

	for ($i = 0; $i < count($list_out); $i++) {
		$users_list[] = $list_out[$i]->user_id;
	}

	$users_list = json_encode($users_list, JSON_UNESCAPED_UNICODE);

	//$users_list = json_encode($banned_users, JSON_UNESCAPED_UNICODE);

	vk_execute($botModule->buildVKSciptAppealByID($data->object->from_id) . "var users=API.users.get({'user_ids':{$users_list}});var msg=', список забаненых пользователей [{$list_number}/{$list_max_number}]:';var i=0;while(i<users.length){var user_first_name=users[i].first_name;msg=msg+'\\n🆘@id'+users[i].id+' ('+user_first_name.substr(0, 2)+'. '+users[i].last_name+') (ID: '+users[i].id+');';i=i+1;};return API.messages.send({'peer_id':{$data->object->peer_id},'message':appeal+msg,'disable_mentions':true});");
}

function manager_baninfo_user($finput)
{
	// Инициализация базовых переменных
	$data = $finput->data;
	$argv = $finput->argv;
	$db = $finput->db;

	$botModule = new BotModule($db);

	if (array_key_exists(0, $data->object->fwd_messages))
		$member_id = $data->object->fwd_messages[0]->from_id;
	elseif (array_key_exists(1, $argv) && bot_get_userid_by_mention($argv[1], $member_id)) {
	} elseif (array_key_exists(1, $argv) && bot_get_userid_by_nick($db, $argv[1], $member_id)) {
	} elseif (array_key_exists(1, $argv) && is_numeric($argv[1])) {
		$member_id = intval($argv[1]);
	} else $member_id = 0;

	if ($member_id == 0) {
		$msg = ", используйте \"!baninfo <пользователь>\".";
		$botModule->sendSilentMessage($data->object->peer_id, $msg, $data->object->from_id);
		return;
	}

	$user_baninfo = BanSystem::getUserBanInfo($db, $member_id);

	if ($user_baninfo !== false) {
		$baninfo = json_encode($user_baninfo, JSON_UNESCAPED_UNICODE);
		$strtime = gmdate("d.m.Y", $user_baninfo->time + 10800);
		vk_execute($botModule->buildVKSciptAppealByID($data->object->from_id) . "
			var baninfo = {$baninfo};
			var users = API.users.get({'user_ids':[baninfo.user_id,baninfo.banned_by],'fields':'first_name_ins,last_name_ins'});
			var user = users[0];
			var banned_by_user = users[1];

			var msg = ', Информация о блокировке:\\n👤Имя пользователя: @id'+user.id+' ('+user.first_name+' '+user.last_name+')\\n🚔Выдан: @id'+banned_by_user.id+' ('+banned_by_user.first_name_ins+' '+banned_by_user.last_name_ins+')\\n📅Время выдачи: {$strtime}\\n✏Причина: '+baninfo.reason+'.';

			return API.messages.send({'peer_id':{$data->object->peer_id},'message':appeal+msg,'disable_mentions':true});");
	} else {
		$botModule->sendSilentMessage($data->object->peer_id, ", ⛔Указанный @id{$member_id} (пользователь) не заблокирован.", $data->object->from_id);
	}
}

function manager_kick_user($finput)
{
	// Инициализация базовых переменных
	$data = $finput->data;
	$argv = $finput->argv;
	$db = $finput->db;

	$botModule = new BotModule($db);

	$permissionSystem = $finput->event->getPermissionSystem();
	if (!$permissionSystem->checkUserPermission($data->object->from_id, 'manage_punishments')) { // Проверка разрешения
		$botModule->sendSystemMsg_NoRights($data);
		return;
	}

	$member_ids = array();
	for ($i = 0; $i < sizeof($data->object->fwd_messages); $i++) {
		$isContinue = true;
		for ($j = 0; $j < sizeof($member_ids); $j++) {
			if ($member_ids[$j] == $data->object->fwd_messages[$i]->from_id) {
				$isContinue = false;
				break;
			}
		}
		if ($isContinue) {
			$member_ids[] = $data->object->fwd_messages[$i]->from_id;
		}
	}
	for ($i = 1; $i < sizeof($argv); $i++) {
		if (bot_get_userid_by_mention($argv[$i], $member_id)) {
			$isContinue = true;
			for ($j = 0; $j < sizeof($member_ids); $j++) {
				if ($member_ids[$j] == $member_id) {
					$isContinue = false;
					break;
				}
			}
			if ($isContinue) {
				$member_ids[] = $member_id;
			}
		} elseif (bot_get_userid_by_nick($db, $argv[$i], $member_id)) {
			$isContinue = true;
			for ($j = 0; $j < sizeof($member_ids); $j++) {
				if ($member_ids[$j] == $member_id) {
					$isContinue = false;
					break;
				}
			}
			if ($isContinue) {
				$member_ids[] = $member_id;
			}
		} elseif (is_numeric($argv[$i])) {
			$member_id = intval($argv[$i]);
			$isContinue = true;
			for ($j = 0; $j < sizeof($member_ids); $j++) {
				if ($member_ids[$j] == $member_id) {
					$isContinue = false;
					break;
				}
			}
			if ($isContinue) {
				$member_ids[] = $member_id;
			}
		}
	}

	if (sizeof($member_ids) == 0) {
		$msg = ", используйте \\\"!kick <упоминание/id>\\\" или перешлите сообщение с командой \\\"!kick\\\".";
		vk_execute($botModule->buildVKSciptAppealByID($data->object->from_id) . "
				return API.messages.send({'peer_id':{$data->object->peer_id},'message':appeal+'{$msg}','disable_mentions':true});
				");
		return;
	} else if (sizeof($member_ids) > 10) {
		$msg = ", нельзя кикнуть более 10 участников одновременно.";
		vk_execute($botModule->buildVKSciptAppealByID($data->object->from_id) . "
				return API.messages.send({'peer_id':{$data->object->peer_id},'message':appeal+'{$msg}','disable_mentions':true});
				");
		return;
	}

	for ($i = 0; $i < count($member_ids); $i++) {
		if ($permissionSystem->checkUserPermission($member_ids[$i], 'manage_punishments'))
			unset($member_ids[$i]);
	}
	sort($member_ids);

	$member_ids_exe_array = implode(',', $member_ids);

	vk_execute($botModule->buildVKSciptAppealByID($data->object->from_id) . "
		var peer_id = {$data->object->peer_id};
		var member_ids = [{$member_ids_exe_array}];
		var users = API.users.get({'user_ids':member_ids});
		var members = API.messages.getConversationMembers({'peer_id':peer_id});

		var msg = ', следующие пользователи были кикнуты:\\n';
		var msg_banned_users = '';

		var j = 0; while(j < users.length){
			var user_id = users[j].id;
			var user_id_index = -1;
			var i = 0; while (i < members.items.length){
				if(members.items[i].member_id == user_id){
					user_id_index = i;
					i = members.items.length;
				};
				i = i + 1;
			};

			if(!members.items[user_id_index].is_admin && user_id_index != -1){
				API.messages.removeChatUser({'chat_id':peer_id-2000000000,'member_id':user_id});
				msg_banned_users = msg_banned_users + '✅@id'+ user_id + ' (' + users[j].first_name + ' ' + users[j].last_name + ')\\n';
			}
			j = j + 1;
		};
		if(msg_banned_users != ''){
			return API.messages.send({'peer_id':peer_id,'message':appeal+msg+msg_banned_users,'disable_mentions':true});
		} else {
			msg = ', ни один пользователь не был кикнут.';
			return API.messages.send({'peer_id':peer_id,'message':appeal+msg,'disable_mentions':true});
		}
		");
}

function manager_online_list($finput)
{
	// Инициализация базовых переменных
	$data = $finput->data;
	$argv = $finput->argv;
	$db = $finput->db;

	$botModule = new BotModule($db);

	if (!array_key_exists(1, $argv)) {
		vk_execute($botModule->buildVKSciptAppealByID($data->object->from_id) . "var members=API.messages.getConversationMembers({'peer_id':{$data->object->peer_id},'fields':'online'});var msg=', 🌐следующие пользователи в сети:\\n';var msg_users='';var i=0;while(i<members.profiles.length){if(members.profiles[i].online==1){var emoji='';if(members.profiles[i].online_mobile==1){emoji='📱';}else{emoji='💻';}msg_users=msg_users+emoji+'@id'+members.profiles[i].id+' ('+members.profiles[i].first_name.substr(0, 2)+'. '+members.profiles[i].last_name+')\\n';}i=i+1;}if(msg_users==''){msg=', 🚫В данный момент нет пользователей в сети!';}else{msg=msg+msg_users;}return API.messages.send({'peer_id':{$data->object->peer_id},'message':appeal+msg,'disable_mentions':true});");
	}
}

function manager_nick($finput)
{
	// Инициализация базовых переменных
	$data = $finput->data;
	$argv = $finput->argv;
	$db = $finput->db;

	$messagesModule = new Bot\Messages($db);
	$messagesModule->setAppealID($data->object->from_id);

	$nick = bot_get_text_by_argv($argv, 1);
	if ($nick !== false) {
		$nick = str_ireplace("\n", "", $nick);
		if ($nick == '') {
			$messagesModule->sendSilentMessage($data->object->peer_id, "%appeal%, ⛔Ник не может быть пустой");
			return;
		}

		if (!array_key_exists(0, $data->object->fwd_messages)) {
			if (mb_strlen($nick) <= 15) {
				$nicknames = (array) $db->executeQuery(new \MongoDB\Driver\Query(['_id' => $db->getDocumentID()], ['projection' => ['_id' => 0, "chat_settings.user_nicknames" => 1]]))->getValue([0, "chat_settings", "user_nicknames"], []);

				// Проверка ника на уникальность без учета регистра
				foreach ($nicknames as $key => $value) {
					$nicknames[$key] = mb_strtolower($value);
				}
				if (array_search(mb_strtolower($nick), $nicknames) !== false) {
					$messagesModule->sendSilentMessage($data->object->peer_id, "%appeal%, ⛔Указанный ник занят!");
					return;
				}

				$bulk = new MongoDB\Driver\BulkWrite;
				$bulk->update(['_id' => $db->getDocumentID()], ['$set' => ["chat_settings.user_nicknames.id{$data->object->from_id}" => $nick]]);
				$db->executeBulkWrite($bulk);

				$messagesModule->sendSilentMessage($data->object->peer_id, "%appeal%, ✅Ник установлен.");
			} else
				$messagesModule->sendSilentMessage($data->object->peer_id, "%appeal%, ⛔Указанный ник больше 15 символов.");
		} else {
			if ($data->object->fwd_messages[0]->from_id <= 0) {
				$messagesModule->sendSilentMessage($data->object->peer_id, "%appeal%, ⛔Ник можно установить только пользователю!");
				return;
			}

			if (mb_strlen($nick) <= 15) {
				$permissionSystem = $finput->event->getPermissionSystem();
				if (!$permissionSystem->checkUserPermission($data->object->from_id, 'change_nick')) { // Проверка разрешения
					$messagesModule->sendSilentMessage($data->object->peer_id, Bot\Messages::MESSAGE_NO_RIGHTS);
					return;
				}
				$nicknames = (array) $db->executeQuery(new \MongoDB\Driver\Query(['_id' => $db->getDocumentID()], ['projection' => ['_id' => 0, "chat_settings.user_nicknames" => 1]]))->getValue([0, "chat_settings", "user_nicknames"], []);
				if (array_search($nick, $nicknames) !== false) {
					$messagesModule->sendSilentMessage($data->object->peer_id, "%appeal%, ⛔Указанный ник занят!");
					return;
				}

				$bulk = new MongoDB\Driver\BulkWrite;
				$bulk->update(['_id' => $db->getDocumentID()], ['$set' => ["chat_settings.user_nicknames.id{$data->object->fwd_messages[0]->from_id}" => $nick]]);
				$db->executeBulkWrite($bulk);

				$messagesModule->sendSilentMessage($data->object->peer_id, "%appeal%, ✅Ник @id{$data->object->fwd_messages[0]->from_id} (пользователя) изменён!");
			} else
				$messagesModule->sendSilentMessage($data->object->peer_id, "%appeal%, ⛔Указанный ник больше 15 символов.");
		}
	} else
		$messagesModule->sendSilentMessage($data->object->peer_id, "%appeal%, ⛔Используйте \"!ник <ник>\" для управления ником.");
}

function manager_remove_nick($data, $db, $finput)
{
	$botModule = new BotModule($db);

	if (!array_key_exists(0, $data->object->fwd_messages)) {
		$bulk = new MongoDB\Driver\BulkWrite;
		$bulk->update(['_id' => $db->getDocumentID()], ['$unset' => ["chat_settings.user_nicknames.id{$data->object->from_id}" => 0]]);
		$db->executeBulkWrite($bulk);

		$msg = ", ✅Ник убран.";
		vk_execute($botModule->buildVKSciptAppealByID($data->object->from_id) . "
			return API.messages.send({'peer_id':{$data->object->peer_id},'message':appeal+'{$msg}','disable_mentions':true});
			");
	} else {
		$permissionSystem = $finput->event->getPermissionSystem();
		if (!$permissionSystem->checkUserPermission($data->object->from_id, 'change_nick')) { // Проверка разрешения
			$botModule->sendSystemMsg_NoRights($data);
			return;
		}

		$request = json_encode(array('peer_id' => $data->object->peer_id, 'message' => "%appeal%, ✅Ник @id{$data->object->fwd_messages[0]->from_id} (пользователя) убран!", 'disable_mentions' => true), JSON_UNESCAPED_UNICODE);
		$request = vk_parse_var($request, "appeal");

		$bulk = new MongoDB\Driver\BulkWrite;
		$bulk->update(['_id' => $db->getDocumentID()], ['$unset' => ["chat_settings.user_nicknames.id{$data->object->fwd_messages[0]->from_id}" => 0]]);
		$db->executeBulkWrite($bulk);

		json_decode(vk_execute($botModule->buildVKSciptAppealByID($data->object->from_id) . "
			API.messages.send({$request});
			"));
	}
}

function manager_show_nicknames($finput)
{
	// Инициализация базовых переменных
	$data = $finput->data;
	$argv = $finput->argv;
	$db = $finput->db;

	$botModule = new BotModule($db);

	if (array_key_exists(1, $argv))
		$list_number_from_word = intval($argv[1]);
	else
		$list_number_from_word = 1;

	$user_nicknames = (array) $db->executeQuery(new \MongoDB\Driver\Query(['_id' => $db->getDocumentID()], ['projection' => ['_id' => 0, "chat_settings.user_nicknames" => 1]]))->getValue([0, "chat_settings", "user_nicknames"], []);
	$nicknames = array();
	foreach ($user_nicknames as $key => $val) {
		$nicknames[] = array(
			'user_id' => substr($key, 2),
			'nick' => $val
		);
	}
	if (count($nicknames) == 0) {
		$request = json_encode(array('peer_id' => $data->object->peer_id, 'message' => "%appeal%, ❗в беседе нет пользователей с никами!", 'disable_mentions' => true), JSON_UNESCAPED_UNICODE);
		$request = vk_parse_var($request, "appeal");
		vk_execute($botModule->buildVKSciptAppealByID($data->object->from_id) . "API.messages.send({$request});");
		return;
	}

	/////////////////////////////////////////////////////
	////////////////////////////////////////////////////
	$list_in = &$nicknames; // Входной список
	$list_out = array(); // Выходной список

	$list_number = $list_number_from_word; // Номер текущего списка
	$list_size = 20; // Размер списка
	////////////////////////////////////////////////////
	if (count($list_in) % $list_size == 0)
		$list_max_number = intdiv(count($list_in), $list_size);
	else
		$list_max_number = intdiv(count($list_in), $list_size) + 1;
	$list_min_index = ($list_size * $list_number) - $list_size;
	if ($list_size * $list_number >= count($list_in))
		$list_max_index = count($list_in) - 1;
	else
		$list_max_index = $list_size * $list_number - 1;
	if ($list_number <= $list_max_number && $list_number > 0) {
		// Обработчик списка
		for ($i = $list_min_index; $i <= $list_max_index; $i++) {
			$list_out[] = $list_in[$i];
		}
	} else {
		// Сообщение об ошибке
		$botModule->sendSilentMessage($data->object->peer_id, ", ⛔указан неверный номер списка!", $data->object->from_id);
		return;
	}
	////////////////////////////////////////////////////
	////////////////////////////////////////////////////

	vk_execute($botModule->buildVKSciptAppealByID($data->object->from_id) . "
		var nicknames = " . json_encode($list_out, JSON_UNESCAPED_UNICODE) . ";
		var users = API.users.get({'user_ids':nicknames@.user_id});
		var msg = appeal+', ники [{$list_number}/{$list_max_number}]:';
		var i = 0; while(i < nicknames.length){
			msg = msg + '\\n✅@id'+nicknames[i].user_id+' ('+users[i].first_name.substr(0, 2)+'. '+users[i].last_name+') — '+nicknames[i].nick;
			i = i + 1;
		}
		return API.messages.send({'peer_id':{$data->object->peer_id},'message':msg,'disable_mentions':true});
		");
}

function manager_greeting($finput)
{
	// Инициализация базовых переменных
	$data = $finput->data;
	$argv = $finput->argv;
	$db = $finput->db;

	$permissionSystem = $finput->event->getPermissionSystem();
	$botModule = new BotModule($db);

	if (!$permissionSystem->checkUserPermission($data->object->from_id, 'customize_chat')) { // Проверка разрешения
		$botModule->sendSystemMsg_NoRights($data);
		return;
	}

	if (array_key_exists(1, $argv))
		$command = mb_strtolower($argv[1]);
	else
		$command = "";
	if ($command == 'установить') {
		$invited_greeting = bot_get_text_by_argv($argv, 2);

		$bulk = new MongoDB\Driver\BulkWrite;
		$bulk->update(['_id' => $db->getDocumentID()], ['$set' => ["chat_settings.invited_greeting" => $invited_greeting]]);
		$db->executeBulkWrite($bulk);

		$msg = ", ✅Приветствие установлено.";
		json_decode(vk_execute($botModule->buildVKSciptAppealByID($data->object->from_id) . "
			API.messages.send({'peer_id':{$data->object->peer_id},'message':appeal+'{$msg}','disable_mentions':true});
			"));
	} elseif ($command == 'показать') {
		$invited_greeting = $db->executeQuery(new MongoDB\Driver\Query(['_id' => $db->getDocumentID()], ['projection' => ['_id' => 0, 'chat_settings.invited_greeting' => 1]]))->getValue('0.chat_settings.invited_greeting', false);
		if ($invited_greeting !== false) {
			$json_request = json_encode(array('peer_id' => $data->object->peer_id, 'message' => "%appeal%, Приветствие в беседе:\n{$invited_greeting}", 'disable_mentions' => true), JSON_UNESCAPED_UNICODE);
			$json_request = vk_parse_var($json_request, "appeal");
			vk_execute($botModule->buildVKSciptAppealByID($data->object->from_id) . "
				API.messages.send({$json_request});
				return 'ok';
				");
		} else {
			$msg = ", ⛔Приветствие не установлено.";
			vk_execute($botModule->buildVKSciptAppealByID($data->object->from_id) . "
				API.messages.send({'peer_id':{$data->object->peer_id},'message':appeal+'{$msg}','disable_mentions':true});
				return 'ok';
				");
		}
	} elseif ($command == 'убрать') {
		$bulk = new MongoDB\Driver\BulkWrite;
		$bulk->update(['_id' => $db->getDocumentID()], ['$unset' => ["chat_settings.invited_greeting" => 0]]);
		$writeResult = $db->executeBulkWrite($bulk);
		if ($writeResult->getModifiedCount() > 0) {
			$msg = ", ✅Приветствие убрано.";
			json_decode(vk_execute($botModule->buildVKSciptAppealByID($data->object->from_id) . "API.messages.send({'peer_id':{$data->object->peer_id},'message':appeal+'{$msg}','disable_mentions':true});"));
		} else {
			$msg = ", ⛔Приветствие не установлено.";
			vk_execute($botModule->buildVKSciptAppealByID($data->object->from_id) . "API.messages.send({'peer_id':{$data->object->peer_id},'message':appeal+'{$msg}','disable_mentions':true});return 'ok';");
		}
	} else {
		$msg = ", ⛔Используйте \"!приветствие установить/показать/убрать\".";
		vk_execute($botModule->buildVKSciptAppealByID($data->object->from_id) . "API.messages.send({'peer_id':{$data->object->peer_id},'message':appeal+'{$msg}','disable_mentions':true});return 'ok';");
	}
}

function manager_show_invited_greetings($data, $db)
{
	$greetings_text = $db->executeQuery(new MongoDB\Driver\Query(['_id' => $db->getDocumentID()], ['projection' => ['_id' => 0, 'chat_settings.invited_greeting' => 1]]))->getValue('0.chat_settings.invited_greeting', false);
	if ($greetings_text !== false && $data->object->action->member_id > 0) {
		$parsing_vars = array('USERID', 'USERNAME', 'USERNAME_GEN', 'USERNAME_DAT', 'USERNAME_ACC', 'USERNAME_INS', 'USERNAME_ABL');

		$system_code = "var user=API.users.get({'user_ids':[{$data->object->action->member_id}],'fields':'first_name_gen,first_name_dat,first_name_acc,first_name_ins,first_name_abl,last_name_gen,last_name_dat,last_name_acc,last_name_ins,last_name_abl'})[0];var USERID='@id'+user.id;var USERNAME=user.first_name+' '+user.last_name;var USERNAME_GEN=user.first_name_gen+' '+user.last_name_gen;var USERNAME_DAT=user.first_name_dat+' '+user.last_name_dat;var USERNAME_ACC=user.first_name_acc+' '+user.last_name_acc;var USERNAME_INS=user.first_name_ins+' '+user.last_name_ins;var USERNAME_ABL=user.first_name_abl+' '+user.last_name_abl;";

		$message_json_request = json_encode(array('peer_id' => $data->object->peer_id, 'message' => $greetings_text), JSON_UNESCAPED_UNICODE);

		for ($i = 0; $i < count($parsing_vars); $i++) {
			$message_json_request = vk_parse_var($message_json_request, $parsing_vars[$i]);
		}

		vk_execute($system_code . "return API.messages.send({$message_json_request});");
		return true;
	}
	return false;
}