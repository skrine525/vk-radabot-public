<?php

namespace Output {
}

namespace Bot {

	use stdClass;
	use VKVariable;

	class ChatEvent
	{
		// Переменныеr
		private $data;								// Объект события ВК
		private $textCommands;				// Массив текстовых команд
		private $buttonCommands;				// Массив команд Text-кнопок
		private $callbackCommands;			// Массив команд Callback-кнопок
		private $nonCommandTextMessageHandlers;		// Массив не командных обработчиков события message_new
		private $hint_char;							// Переменная знака, отвещающий за подсказски

		// Объекты различных модулей
		private $db;								// База данных
		private $chatModes;							// Режимы беседы
		private $permissionSystem;					// Система прав

		// Константы
		const COMMAND_RESULT_OK = 0;				// Константа результата выполнения команды без ошибок
		const COMMAND_RESULT_NO_DB = 1;				// Константа результата выполнения команды с ошибкой, которая не способна работать без Базы данных
		const COMMAND_RESULT_UNKNOWN = 2;			// Константа резулятата выполнения неизвестной команды
		const COMMAND_RESULT_INVALID_DATA = 3;		// Константа результата выполнения команды с неправильно переданными данными

		function __construct(\stdClass $data)
		{
			if ($data->object->peer_id > 2000000000) {
				// Базовые переменные
				$this->data = $data;
				$this->textCommands = [];
				$this->buttonCommands = [];
				$this->callbackCommands = [];
				$this->nonCommandTextMessageHandlers = [];

				// Подгрузка базы данных
				$database_host = bot_getconfig("DATABASE_HOST");
				$database_port = bot_getconfig("DATABASE_PORT");
				$database_name = bot_getconfig("DATABASE_NAME");
				$this->db = new \Database\Manager("mongodb://{$database_host}:{$database_port}", $database_name, $this->data->object->peer_id);

				// Подгрузка других модулей
				$this->chatModes = new \ChatModes($this->db);					// Режимы беседы
				$this->permissionSystem = new \PermissionSystem($this->db);		// Систима прав
			} else {
				error_log("Error: {$data->object->peer_id} is not chat peer_id.");
				exit;
			}
		}

		public function getData()
		{
			return $this->data;
		}

		public function getDatabase()
		{
			return $this->db;
		}

		public function getChatModes()
		{
			return $this->chatModes;
		}

		public function getPermissionSystem()
		{
			return $this->permissionSystem;
		}

		public function setHintChar(string $char)
		{
			if (mb_strlen($char) == 1) {
				$this->hint_char = $char;
				return true;
			} else
				return false;
		}

		private static function getArrayParam($array, $name, $type, $default)
		{
			if (array_key_exists($name, $array) && gettype($array[$name]) == $type)
				return $array[$name];
			else
				return $default;
		}

		public function addNonCommandTextMessageHandler($callback)
		{
			if (array_search($callback, $this->nonCommandTextMessageHandlers) === false && is_callable($callback)) {
				$this->nonCommandTextMessageHandlers[] = $callback;
				return true;
			}
			return false;
		}

		public function addTextMessageCommand(string $command, callable $callback, array $other_params = [])
		{
			if (!$this->isTextMessageCommand($command)) {
				$this->textCommands[$command] = (object) array(
					'callback' => $callback,
					'callback_argv' => self::getArrayParam($other_params, 'callback_argv', 'array', []),
					'ignore_db' => self::getArrayParam($other_params, 'ignore_db', 'boolean', false),
					'output_system' => self::getArrayParam($other_params, 'output_system', 'boolean', false),
					'only_text_input' => self::getArrayParam($other_params, 'only_text_input', 'boolean', false)
				);
				return true;
			} else
				return false;
		}

		public function isTextMessageCommand(string $command)
		{
			if (array_key_exists($command, $this->textCommands))
				return $this->textCommands[$command];
			else
				return false;
		}

		public function addTextButtonCommand(string $command, callable $callback, array $other_params = [])
		{
			if (!$this->isTextButtonCommand($command)) {
				$this->buttonCommands[$command] = (object) array(
					'callback' => $callback,
					'callback_argv' => self::getArrayParam($other_params, 'callback_argv', 'array', []),
					'ignore_db' => self::getArrayParam($other_params, 'ignore_db', 'boolean', false)
				);
				return true;
			} else
				return false;
		}

		public function isTextButtonCommand(string $command)
		{
			if (array_key_exists($command, $this->buttonCommands))
				return $this->buttonCommands[$command];
			else
				return false;
		}

		public function addCallbackButtonCommand(string $command, callable $callback, array $other_params = [])
		{
			if (!$this->isCallbackButtonCommand($command)) {
				$this->callbackCommands[$command] = (object) array(
					'callback' => $callback,
					'callback_argv' => self::getArrayParam($other_params, 'callback_argv', 'array', []),
					'ignore_db' => self::getArrayParam($other_params, 'ignore_db', 'boolean', false)
				);
				return true;
			} else
				return false;
		}

		public function isCallbackButtonCommand(string $command)
		{
			if (array_key_exists($command, $this->callbackCommands))
				return $this->callbackCommands[$command];
			else
				return false;
		}

		public function getTextMessageCommandList()
		{
			$list = array();
			foreach ($this->textCommands as $key => $value) {
				$list[] = $key;
			}
			return $list;
		}

		public function getTextButtonCommandList()
		{
			$list = array();
			foreach ($this->buttonCommands as $key => $value) {
				$list[] = $key;
			}
			return $list;
		}

		public function getCallbackButtonCommandList()
		{
			$list = array();
			foreach ($this->callbackCommands as $key => $value) {
				$list[] = $key;
			}
			return $list;
		}

		public function exit()
		{
			//unset($this);
		}

		public function runTextCommand(\stdClass $data, OutputSystem $output)
		{
			if (gettype($data) == "object") {
				$argv = bot_parse_argv($data->object->text); // Извлекаем аргументы из сообщения
				$command = mb_strtolower(bot_get_array_value($argv, 0, "")); // Переводим команду в нижний регистр

				if ($this->isTextMessageCommand($command)) {
					$command_data = $this->textCommands[$command];

					// Проверка на существование беседы в Базе данных, если команда не способна игнорировать это
					if (!$command_data->ignore_db && !$this->db->isExists())
						return (object) ['code' => ChatEvent::COMMAND_RESULT_NO_DB];

					if ($command_data->output_system) {
						$finput = (object) array(
							'data' => $data,
							'argv' => $argv,
							'db' => $this->db,
							'event' => $this,
							'output' => $output
						);
					} else {
						$finput = (object) array(
							'data' => $data,
							'argv' => $argv,
							'db' => $this->db,
							'event' => $this
						);
					}
					$callback = $command_data->callback; 										// Получение Callback'а
					$execution_time = microtime(true);											// Начало подсчета времени исполнения Callback'а
					$callback_argv = array_merge([$finput], $command_data->callback_argv);		// Сливание аргументов Callback'а
					call_user_func_array($callback, $callback_argv); 							// Выполнение Callback'а
					$execution_time = microtime(true) - $execution_time;						// Конец подсчета времени исполнения Callback'а
					return (object) ['code' => ChatEvent::COMMAND_RESULT_OK, 'command' => $command, 'finput' => $finput, 'execution_time' => $execution_time];
				}
				return (object) ['code' => ChatEvent::COMMAND_RESULT_UNKNOWN, 'command' => $command];
			}
			return (object) ['code' => ChatEvent::COMMAND_RESULT_INVALID_DATA];
		}

		public function runButtonCommand(\stdClass $data)
		{
			if (gettype($data) == "object") {
				if (property_exists($data->object, "payload")) {
					$payload = (object) json_decode($data->object->payload);
					if (!is_null($payload) && property_exists($payload, "command")) {
						if ($this->isTextButtonCommand($payload->command)) {
							$command_data = $this->buttonCommands[$payload->command];

							// Проверка на существование беседы в Базе данных, если команда не способна игнорировать это
							if (!$command_data->ignore_db && !$this->db->isExists())
								return (object) ['code' => ChatEvent::COMMAND_RESULT_NO_DB];

							$finput = (object) array(
								'data' => $data,
								'payload' => $payload,
								'db' => $this->db,
								'event' => $this
							);

							$callback = $command_data->callback; 										// Получение Callback'а
							$execution_time = microtime(true);											// Начало подсчета времени исполнения Callback'а
							$callback_argv = array_merge([$finput], $command_data->callback_argv);		// Сливание аргументов Callback'а
							call_user_func_array($callback, $callback_argv); 							// Выполнение Callback'а
							$execution_time = microtime(true) - $execution_time;						// Конец подсчета времени исполнения Callback'а
							return (object) ['code' => ChatEvent::COMMAND_RESULT_OK, 'command' => $payload->command, 'finput' => $finput, 'execution_time' => $execution_time];
						}
						return (object) ['code' => ChatEvent::COMMAND_RESULT_UNKNOWN, 'command' => $payload->command];
					}
				}
			}
			return (object) ['code' => ChatEvent::COMMAND_RESULT_INVALID_DATA];
		}

		public function runCallbackCommand(\stdClass $data)
		{
			if (gettype($data) == "object") {
				if (property_exists($data->object, "payload") && gettype($data->object->payload) == 'array') {
					$payload = $data->object->payload;
					if (array_key_exists(0, $payload)) {
						if ($this->isCallbackButtonCommand($payload[0])) {
							$command_data = $this->callbackCommands[$payload[0]];

							// Проверка на существование беседы в Базе данных, если команда не способна игнорировать это
							if (!$command_data->ignore_db && !$this->db->isExists())
								return (object) ['code' => ChatEvent::COMMAND_RESULT_NO_DB];

							$finput = (object) array(
								'data' => $data,
								'payload' => $payload,
								'db' => $this->db,
								'event' => $this
							);

							$callback = $command_data->callback; 										// Получение Callback'а
							$execution_time = microtime(true);											// Начало подсчета времени исполнения Callback'а
							$callback_argv = array_merge([$finput], $command_data->callback_argv);		// Сливание аргументов Callback'а
							call_user_func_array($callback, $callback_argv); 							// Выполнение Callback'а
							$execution_time = microtime(true) - $execution_time;						// Конец подсчета времени исполнения Callback'а
							return (object) ['code' => ChatEvent::COMMAND_RESULT_OK, 'command' => $payload[0], 'finput' => $finput, 'execution_time' => $execution_time];
						}
						return (object) ['code' => ChatEvent::COMMAND_RESULT_UNKNOWN, 'command' => $payload[0]];
					}
				}
			}
			return (object) ['code' => ChatEvent::COMMAND_RESULT_INVALID_DATA];
		}

		public function handle()
		{
			switch ($this->data->type) {
				case 'message_new':
					if ($this->data->object->from_id <= 0) { // Игнорирование сообщений других чат-ботов
						return false;
					}

					// Обработка клавиатурных команд
					$result = $this->runButtonCommand($this->data);
					if ($result->code == ChatEvent::COMMAND_RESULT_OK)
						return true;
					elseif ($result->code == ChatEvent::COMMAND_RESULT_NO_DB) {
						bot_message_not_reg($this->data);
						return false;
					}

					// Обработка тектовых команд
					$output = new OutputSystem(OutputSystem::TYPE_MSG_NEW, $this->data);
					$result = $this->runTextCommand($this->data, $output);
					if ($result->code == ChatEvent::COMMAND_RESULT_OK)
						return true;
					elseif ($result->code == ChatEvent::COMMAND_RESULT_NO_DB) {
						bot_message_not_reg($this->data);
						return false;
					} elseif ($this->db->isExists() && gettype($this->hint_char) == "string" && $result->code == ChatEvent::COMMAND_RESULT_UNKNOWN && mb_strlen($result->command) >= 1 && mb_substr($result->command, 0, 1) == $this->hint_char) {
						// Подсказки, если пользователь неправильно ввел команду
						$commands = $this->getTextMessageCommandList();
						$commands_data = [];
						foreach ($commands as $key => $value) {
							similar_text($value, $result->command, $perc);
							if ($perc >= 70)
								$commands_data[$value] = $perc;
							if (count($commands_data) >= 10)
								break;
						}
						if (count($commands_data) > 0) {
							arsort($commands_data);
							$messagesModule = new Messages($this->db);
							$messagesModule->setAppealID($this->data->object->from_id);
							$messagesModule->sendSilentMessageWithListFromArray($this->data->object->peer_id, "%appeal%, Возможно вы, имели ввиду:", array_keys($commands_data));
						}
						return false;
					}

					// Обработка не командный сообщений
					if (count($this->nonCommandTextMessageHandlers) > 0) {
						if (!$this->db->isExists()) // Проверка на регистрацию в системе
							return false;
						$finput = (object) array(
							'data' => $this->data,
							'db' => $this->db,
							'event' => $this
						);
						foreach ($this->nonCommandTextMessageHandlers as $key => $value) {
							$callback_return_value = call_user_func_array($value, [$finput]);	// Выполнение Callback'а
							if ($callback_return_value)
								return true;
						}
						return false;
					}
					break;

				case 'message_event':
					if ($this->data->object->user_id <= 0) { // Игнорирование действий сообщений других чат-ботов
						return false;
					}

					// Обработка клавиатурных команд
					$result = $this->runCallbackCommand($this->data);
					if ($result->code == ChatEvent::COMMAND_RESULT_OK)
						return true;
					elseif ($result->code == ChatEvent::COMMAND_RESULT_NO_DB) {
						bot_message_not_reg($this->data);
						return false;
					} else {
						bot_show_snackbar($this->data->object->event_id, $this->data->object->user_id, $this->data->object->peer_id, '⛔ Неизвестная команда.');
						return false;
					}
					break;
			}
			return false;
		}
	}

	class Messages
	{
		private $db;
		private $appeal_id;
		private $appeal_varname;

		// Константы шаблонных сообщений
		const MESSAGE_NO_RIGHTS = "%appeal%, ⛔У вас нет прав для использования этой команды.";

		public function __construct($db = false)
		{
			$this->db = $db;
			$this->appeal_id = null;
		}

		public function setAppealID($appeal_id, $varname = "appeal")
		{
			$this->appeal_id = $appeal_id;
			$this->appeal_varname = $varname;
		}

		public function getAppealID()
		{
			return $this->appeal_id;
		}

		public function buildVKSciptAppealByID($user_id, $varname = "appeal")
		{ // Создание переменной appeal с обращением к пользователю, посредством VKScript и vk_execute()
			if ($this->db !== false)
				$user_nick = $this->db->executeQuery(new \MongoDB\Driver\Query(['_id' => $this->db->getDocumentID()], ['projection' => ['_id' => 0, "chat_settings.user_nicknames.id{$user_id}" => 1]]))->getValue([0, "chat_settings", "user_nicknames", "id{$user_id}"], false);
			else
				$user_nick = false;

			if ($user_nick !== false) {
				return "var user=API.users.get({'user_id':{$user_id},'fields':'screen_name'})[0];var {$varname}='@'+user.screen_name+' ({$user_nick})';user=null;";
			} else {
				return "var user=API.users.get({'user_id':{$user_id},'fields':'screen_name'})[0];var {$varname}='@'+user.screen_name+' ('+user.first_name.substr(0, 2)+'. '+user.last_name+')';user=null;";
			}
		}

		function sendMessage($peer_id, $message, $params = array())
		{ // Отправка сообщений
			// Создание параметров запроса
			$request_array = [];
			foreach ($params as $key => $value) {
				$request_array[$key] = $value;
			}
			$request_array['peer_id'] = $peer_id;
			$request_array['message'] = $message;
			$json_request = json_encode($request_array, JSON_UNESCAPED_UNICODE);

			// Парсинг обращения в сообщении
			$appeal_code = "";
			if (gettype($this->appeal_id) == "integer") {
				$appeal_code = $this->buildVKSciptAppealByID($this->appeal_id, $this->appeal_varname);
				$json_request = vk_parse_var($json_request, $this->appeal_varname);
			}

			// Запрос
			return vk_execute("{$appeal_code}return API.messages.send({$json_request});");
		}

		function editMessage($peer_id, $conversation_message_id, $message, $params = array())
		{
			// Создание параметров запроса
			$request_array = [];
			foreach ($params as $key => $value) {
				$request_array[$key] = $value;
			}
			$request_array['peer_id'] = $peer_id;
			$request_array['conversation_message_id'] = $conversation_message_id;
			$request_array['message'] = $message;
			$json_request = json_encode($request_array, JSON_UNESCAPED_UNICODE);

			// Парсинг обращения в сообщении
			$appeal_code = "";
			if (gettype($this->appeal_id) == "integer") {
				$appeal_code = $this->buildVKSciptAppealByID($this->appeal_id, $this->appeal_varname);
				$json_request = vk_parse_var($json_request, $this->appeal_varname);
			}

			// Запрос
			return vk_execute("{$appeal_code}return API.messages.edit({$json_request});");
		}

		function sendSilentMessage($peer_id, $message, $params = array())
		{ // Отправка сообщений без упоминаний
			if (gettype($params) == "array")
				$params['disable_mentions'] = true;
			else
				$params = ['disable_mentions' => true];
			return $this->sendMessage($peer_id, $message, $params);
		}

		function sendSilentMessageWithListFromArray($peer_id, $message = "", $list = array(), $keyboard = null)
		{ // Legacy
			foreach ($list as $key => $value) {
				$message .= "\n• {$value}";
			}
			if (is_null($keyboard))
				$this->sendSilentMessage($peer_id, $message);
			else
				$this->sendSilentMessage($peer_id, $message, array("keyboard" => $keyboard));
		}
	}

	class ListBuilder
	{
		private $list;
		private $size;

		function __construct($list, $size)
		{
			if (gettype($list) == "array" && gettype($size) == "integer") {
				$this->list = $list;
				$this->size = $size;
			} else
				return false;
		}

		public function build($list_number)
		{
			$list_out = array(); // Выходной список

			if (count($this->list) % $this->size == 0)
				$list_max_number = intdiv(count($this->list), $this->size);
			else
				$list_max_number = intdiv(count($this->list), $this->size) + 1;
			$list_min_index = ($this->size * $list_number) - $this->size;
			if ($this->size * $list_number >= count($this->list))
				$list_max_index = count($this->list) - 1;
			else
				$list_max_index = $this->size * $list_number - 1;
			if ($list_number <= $list_max_number && $list_number > 0) {
				for ($i = $list_min_index; $i <= $list_max_index; $i++) {
					$list_out[] = $this->list[$i];
				}
			} else
				return (object) array('result' => false);

			return (object) array(
				'result' => true,
				'list' => (object) array(
					'number' => $list_number,
					'max_number' => $list_max_number,
					'out' => $list_out
				)
			);
		}
	}

	class Config
	{
		private static $data;
		private static $loaded;

		public static function get($name)
		{
			if (self::$loaded !== true) {
				$data = json_decode(file_get_contents(BOTPATH_CONFIGFILE), true);
				if ($data === false) {
					error_log('Unable to read config.json file. File not exists or invalid.');
					exit;
				}
				self::$data = $data;
			}

			if (array_key_exists($name, self::$data))
				return self::$data[$name];
			else
				return null;
		}
	}

	class MultiCommand
	{
		private $parent_command;
		private $sub_commands;

		function __construct(string $parent_command)
		{
			$this->parent_command = mb_strtolower($parent_command);
			$this->sub_commands = [];
		}

		public function addSubCommand(string $command, callable $callback, string $description)
		{
			$command_lower = mb_strtolower($command);
			if (!array_key_exists($command_lower, $this->sub_commands)) {
				$this->sub_commands[$command_lower] = (object) [
					'callback' => $callback,
					'description' => $description
				];
				return true;
			}
			return false;
		}

		public function handle(\stdClass $finput, int $sub_index)
		{
			$sub_command = mb_strtolower(bot_get_array_value($finput->argv, $sub_index, ''));
			if (array_key_exists($sub_command, $this->sub_commands)) {
				$callback = $this->sub_commands[$sub_command]->callback;
				call_user_func_array($callback, [$finput]);
			} else {
				$messagesModule = new Messages($finput->db);
				$messagesModule->setAppealID($finput->data->object->from_id);
				$description_array = [];
				foreach ($this->sub_commands as $command => $data) {
					$description_array[] = "{$this->parent_command} {$command} - {$data->description}";
				}
				$messagesModule->sendSilentMessageWithListFromArray($finput->data->object->peer_id, "%appeal%, Используйте:", $description_array);
			}
		}
	}

	class OutputSnackbar
	{
		private $text;

		function __construct($text)
		{
			$this->text = $text;
		}

		public function _get()
		{
			return $this->text;
		}
	}

	class OutputMessageSend
	{
		private $raw;
		private $parsing;

		private function _setparam($name, $param, $parse_array)
		{
			if (!array_key_exists($name, $this->raw) && !array_key_exists($name, $this->parsing)) {
				if ($parse_array)
					$this->parsing[$name] = $param;
				else
					$this->raw[$name] = $param;
				return true;
			} else
				return false;
		}

		public function _get()
		{
			return (object) ['raw' => $this->raw, 'parsing' => $this->parsing];
		}

		public function __construct($message = '', $parse_array = false)
		{
			$this->raw = [];
			$this->parsing = [];

			if ($message != '')
				$this->_setparam('message', $message, $parse_array);
		}

		public function message($message, $parse_array = false)
		{
			return $this->_setparam('message', $message, $parse_array);
		}

		public function attachment($attachment, $parse_array = false)
		{
			return $this->_setparam('attachment', $attachment, $parse_array);
		}

		public function keyboard($keyboard, $parse_array = false)
		{
			return $this->_setparam('keyboard', $keyboard, $parse_array);
		}

		public function template($template, $parse_array = false)
		{
			return $this->_setparam('template', $template, $parse_array);
		}
	}

	class OutputMessageEdit
	{
		private $raw;
		private $parsing;

		private function _setparam($name, $param, $parse_array)
		{
			if (!array_key_exists($name, $this->raw) && !array_key_exists($name, $this->parsing)) {
				if ($parse_array)
					$this->parsing[$name] = $param;
				else
					$this->raw[$name] = $param;
				return true;
			} else
				return false;
		}

		public function _get()
		{
			return (object) ['raw' => $this->raw, 'parsing' => $this->parsing];
		}

		public function __construct($message = '', $parse_array = false)
		{
			$this->raw = [];
			$this->parsing = [];

			if ($message != '')
				$this->_setparam('message', $message, $parse_array);
		}

		public function message($message, $parse_array = false)
		{
			return $this->_setparam('message', $message, $parse_array);
		}

		public function attachment($attachment, $parse_array = false)
		{
			return $this->_setparam('attachment', $attachment, $parse_array);
		}

		public function keyboard($keyboard, $parse_array = false)
		{
			return $this->_setparam('keyboard', $keyboard, $parse_array);
		}

		public function template($template, $parse_array = false)
		{
			return $this->_setparam('template', $template, $parse_array);
		}
	}

	class OutputObject
	{
		protected $output_data;

		function __construct()
		{
			$this->output_data = [];

			$this->output_data['user_appeal'] = (object) ['user_id' => 0, 'nickname' => false];
		}

		public function getObject()
		{
			return (object) $this->output_data;
		}

		public function setUserSettings(\Database\Manager $db, int $user_id)
		{
			$query_projection = [
				'_id' => 0,
				"chat_settings.user_nicknames.id{$user_id}" => 1
			];
			$userSettings = $db->executeQuery(new \MongoDB\Driver\Query(['_id' => $db->getDocumentID()], ['projection' => $query_projection]));

			$this->output_data['user_appeal']->user_id = $user_id;
			$this->output_data['user_appeal']->nickname = $userSettings->getValue([0, "chat_settings", "user_nicknames", "id{$user_id}"], false);
		}
	}

	class MessageOutputObject extends OutputObject
	{
		private $send_params;
		private $edit_params;

		function __construct()
		{
			$this->send_params = (object) ['array' => [], 'for_parsing' => []];
			$this->edit_params = (object) ['array' => [], 'for_parsing' => []];
		}

		public function getOutputObject()
		{
			$object = (object) [
				'send_params' => $this->send_params,
				'edit_params' => $this->edit_params,
				'user_appeal' => $this->user_appeal
			];
			return $object;
		}

		public function edit($param, $value, $parse_array = false)
		{
			if ($parse_array)
				$this->edit_params->for_parsing[$param] = $value;
			else
				$this->edit_params->array[$param] = $value;
		}

		public function send($param, $value, $parse_array = false)
		{
			if ($parse_array)
				$this->send_params->for_parsing[$param] = $value;
			else
				$this->send_params->array[$param] = $value;
		}

		/////////////////////////////////////////////////////////////////////////////////////////////////////////////////
		/////////////////////////////////////////////////////////////////////////////////////////////////////////////////
		// Общие параметры

		public function message_for_both($message, $parse_array = false)
		{
			$this->send('message', $message, $parse_array);
			$this->edit('message', $message, $parse_array);
		}

		public function attachment_for_both($attachment, $parse_array = false)
		{
			$this->send('attachment', $attachment, $parse_array);
			$this->edit('attachment', $attachment, $parse_array);
		}

		public function keyboard_for_both($keyboard, $parse_array = false)
		{
			$this->send('keyboard', $keyboard, $parse_array);
			$this->edit('keyboard', $keyboard, $parse_array);
		}

		public function template_for_both($template, $parse_array = false)
		{
			$this->send('template', $template, $parse_array);
			$this->edit('template', $template, $parse_array);
		}
	}

	class NoticeOutputObject extends OutputObject
	{
		private $snackbar;
		private $edit;

		function __construct()
		{
			$this->snackbar = (object) ['text' => '', 'parse_array' => false];
			$this->edit_params = (object) ['array' => [], 'for_parsing' => []];
		}

		public function getOutputObject()
		{
			$object = (object) [
				'snackbar' => $this->snackbar,
				'edit_params' => $this->edit_params,
				'user_appeal' => $this->user_appeal
			];
			return $object;
		}

		public function edit($param, $value, $parse_array = false)
		{
			if ($parse_array)
				$this->edit_params->for_parsing[$param] = $value;
			else
				$this->edit_params->array[$param] = $value;
		}

		/////////////////////////////////////////////////////////////////////////////////////////////////////////////////
		/////////////////////////////////////////////////////////////////////////////////////////////////////////////////
		// Общие параметры

		public function text_for_snackbar($text, $parse_array = false)
		{
			$this->snackbar->text = $text;
			$this->snackbar->parse_array = $parse_array;
		}

		public function message_for_edit($message, $parse_array = false)
		{
			$this->edit('message', $message, $parse_array);
		}

		public function attachment_for_edit($attachment, $parse_array = false)
		{
			$this->edit('attachment', $attachment, $parse_array);
		}

		public function keyboard_for_edit($keyboard, $parse_array = false)
		{
			$this->edit('keyboard', $keyboard, $parse_array);
		}

		public function template_for_edit($template, $parse_array = false)
		{
			$this->edit('template', $template, $parse_array);
		}
	}

	class OutputSystem
	{
		private $type;												// Тип вызова
		private $event_data;										// Объект события VK
		private $code;												// Переменная кода vk_execute
		private $lastHandleResult;									// Результат последней обработки

		const TYPE_NULL = 0;
		const TYPE_MSG_NEW = 1;
		const TYPE_MSG_EVENT = 2;

		function __construct(...$params)
		{
			$this->code = "";
			$this->lastHandleResult = "";

			if (array_key_exists(0, $params)) {
				if ($params[0] === self::TYPE_MSG_NEW && array_key_exists(1, $params)) {
					$this->type = self::TYPE_MSG_NEW;
					$this->event_data = $params[1];
				} elseif ($params[0] === self::TYPE_MSG_EVENT && array_key_exists(1, $params)) {
					$this->type = self::TYPE_MSG_EVENT;
					$this->event_data = $params[1];
				} else {
					$this->type = self::TYPE_NULL;
				}
			} else
				$this->type = self::TYPE_NULL;
		}

		function getType()
		{
			return $this->type;
		}

		function getLastHandleResult()
		{
			return $this->lastHandleResult;
		}

		public function addCode(...$code)
		{
			foreach ($code as $c)
				$this->code .= $c;
		}

		public function clearCode()
		{
			$this->code = '';
		}

		private function handleCode()
		{
			error_log($this->code);
			$result = vk_execute($this->code);
			error_log($result);
			$this->clearCode();
			return $result;
		}

		public function handleNotice(NoticeOutputObject $noticeOutputObject)
		{
			$object = $noticeOutputObject->getOutputObject();
			$vk_vars = new VKVariable();

			if ($this->type == self::TYPE_MSG_NEW) {
				$request_array = $object->edit_params->array;
				$request_array['peer_id'] = $this->event_data->object->peer_id;

				// Создание обращения
				if ($object->user_appeal->nickname !== false)
					$vk_vars->var('var appeal', ['str', '@id', 'int', $object->user_appeal->user_id, 'str', " ({$object->user_appeal->nickname}), "], true);
				elseif ($object->user_appeal->user_id != 0)
					$this->addCode("var user=API.users.get({'user_id':{$object->user_appeal->user_id},'fields':'screen_name'})[0];var appeal='@'+user.screen_name+' ('+user.first_name.substr(0, 2)+'. '+user.last_name+'), ';user=null;");
				else
					$this->addCode("var appeal='';");

				// Создание запрос messages.send
				$vk_vars->var('var reqm', $request_array);
				foreach ($object->edit_params->for_parsing as $key => $value) {
					$vk_vars->var("reqm.{$key}", $value, true);
				}
				$this->addCode($vk_vars->getCode(), "API.messages.send(reqm);");

				$this->handleCode();
			} elseif ($this->type == self::TYPE_MSG_EVENT) {
				$request_array = ['event_id' => $this->event_data->object->event_id, 'user_id' => $this->event_data->object->user_id, 'peer_id' => $this->event_data->object->peer_id];

				// Создание обращения
				if ($object->user_appeal->nickname !== false)
					$vk_vars->var('var appeal', ['str', "{$object->user_appeal->nickname}, "], true);
				elseif ($object->user_appeal->user_id != 0)
					$this->addCode("var user=API.users.get({'user_id':{$object->user_appeal->user_id},'fields':'screen_name'})[0];var appeal=user.first_name.substr(0, 2)+'. '+user.last_name+', ';user=null;");
				else
					$this->addCode("var appeal='';");

				// Создание запрос snackbar
				$vk_vars->var('var reqm', $request_array);
				$vk_vars->var('var stxt', $object->snackbar->text, $object->snackbar->parse_array);
				$vk_vars->var('reqm.event_data', ['str', '{"type":"show_snackbar","text":"', 'var', 'stxt', 'str', '"}'], true);
				$this->addCode($vk_vars->getCode(), "API.messages.sendMessageEventAnswer(reqm);");

				$this->handleCode();
			} else
				return true;
			return true;
		}

		public function handleMessage(MessageOutputObject $messageOutputObject)
		{
			$object = $messageOutputObject->getOutputObject();
			$vk_vars = new VKVariable();

			if ($this->type == self::TYPE_MSG_NEW) {
				$request_array = $object->send_params->array;
				$request_array['peer_id'] = $this->event_data->object->peer_id;

				// Создание обращения
				if ($object->user_appeal->nickname !== false)
					$vk_vars->var('var appeal', ['str', '@id', 'int', $object->user_appeal->user_id, 'str', " ({$object->user_appeal->nickname}), "], true);
				elseif ($object->user_appeal->user_id != 0)
					$this->addCode("var user=API.users.get({'user_id':{$object->user_appeal->user_id},'fields':'screen_name'})[0];var appeal='@'+user.screen_name+' ('+user.first_name.substr(0, 2)+'. '+user.last_name+'), ';user=null;");
				else
					$this->addCode("var appeal='';");

				// Создание запрос messages.send
				$vk_vars->var('var reqm', $request_array);
				foreach ($object->send_params->for_parsing as $key => $value) {
					$vk_vars->var("reqm.{$key}", $value, true);
				}
				$this->addCode($vk_vars->getCode(), "API.messages.send(reqm);");

				$this->handleCode();
			} elseif ($this->type == self::TYPE_MSG_EVENT) {
				$request_array = $object->send_params->array;
				$request_array['peer_id'] = $this->event_data->object->peer_id;
				$request_array['conversation_message_id'] = $this->event_data->object->conversation_message_id;

				// Создание обращения
				if ($object->user_appeal->nickname !== false)
					$vk_vars->var('var appeal', ['str', '@id', 'int', $object->user_appeal->user_id, 'str', " ({$object->user_appeal->nickname}), "], true);
				elseif ($object->user_appeal->user_id != 0)
					$this->addCode("var user=API.users.get({'user_id':{$object->user_appeal->user_id},'fields':'screen_name'})[0];var appeal='@'+user.screen_name+' ('+user.first_name.substr(0, 2)+'. '+user.last_name+'), ';user=null;");
				else
					$this->addCode("var appeal='';");

				// Создание запрос messages.edit
				$vk_vars->var('var reqm', $request_array);
				foreach ($object->send_params->for_parsing as $key => $value) {
					$vk_vars->var("reqm.{$key}", $value, true);
				}
				$this->addCode($vk_vars->getCode(), "API.messages.edit(reqm);");

				$this->handleCode();
			} else
				return true;
			return true;
		}
	}
}

namespace {
	//////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
	//////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

	// Константы путей бота
	define('BOTPATH_SYSTEM', __DIR__);										// Каталог PHP кода бота
	define('BOTPATH_MAIN', dirname(__DIR__));								// Каталог ядра бота (Вместе с Python модулями)
	define('BOTPATH_ROOT', dirname(BOTPATH_MAIN));							// Корневой каталог бота
	define('BOTPATH_DATA', BOTPATH_ROOT . "/data");							// Каталог данных бота
	define('BOTPATH_TMP', BOTPATH_ROOT . "/tmp");							// Каталог временных файлов бота
	define('BOTPATH_CONFIGFILE', BOTPATH_DATA . "/config.json");			// Файл настроек бота
	define('BOTPATH_MANAGERDATAFILE', BOTPATH_DATA . "/manager.json");		// Файл данный менеджера

	mb_internal_encoding("UTF-8");											// UTF-8 как основная кодировка для mbstring

	$GLOBALS['modules_importtime_start'] = microtime(true);					// Время подключения модулей: Начало

	// Составные модули бота
	require_once(__DIR__ . "/vk.php"); 										// Модуль, отвечающий за все взаимодействия с VK API
	require_once(__DIR__ . "/database.php"); 								// Модуль, отвечающий за взаимодействие основной базы данных бота
	require_once(__DIR__ . "/economy.php"); 								// Модуль, отвечающий за систему Экономики
	require_once(__DIR__ . "/fun.php"); 									// Модуль, отвечающий за развлечения
	require_once(__DIR__ . "/roleplay.php"); 								// Модуль, отвечающий за Roleplay команды
	require_once(__DIR__ . "/manager.php"); 								// Модуль, отвечающий за управление беседой

	$GLOBALS['modules_importtime_end'] = microtime(true);					// Время подключения модулей: Конец

	function bot_handle_event($data, $cmd, $hndl, $integration = false)
	{
		if ($data->object->peer_id < 2000000000) { 										// Запрет использование бота в лс
			///////////////////////////
			/// Обработка бота в Личном
			///////////////////////////
			vk_call('messages.send', array('peer_id' => $data->object->peer_id, 'message' => 'Бот работает только в беседах. Вы можете добавить бота в беседу соответствующей кнопкой в меню бота на главной странице.'));
		} else {
			///////////////////////////
			/// Обработка бота в Беседе
			///////////////////////////

			// Инициализируем класс
			$event = new Bot\ChatEvent($data);
			$event->setHintChar("!");															// Устанавливаем первый символ для отображения подсказок

			if ($cmd) {

				$GLOBALS['cmd_initime_start'] = microtime(true);								// Время инициализации команд: Начало

				bot_initcmd($event);															// Инициализация команд модуля bot
				manager_initcmd($event);														// Инициализация команд модуля manager
				roleplay_initcmd($event);														// RP-команды
				fun_initcmd($event);															// Fun-команды
				economy_initcmd($event);														// Economy

				$GLOBALS['cmd_initime_end'] = microtime(true);									// Время инициализации команд: Конец
			}

			if ($hndl) {
				// Обработчики текстовых сообщений без команд
				$event->addNonCommandTextMessageHandler('bot_message_action_handler');		// Обработчик событий в сообщениях
				$event->addNonCommandTextMessageHandler('fun_handler');							// Обработчик фанового модуля
			}

			if(!$integration){
				// Если не интеграция, то выполенение кода
				bot_pre_handle($event);															// Функция предварительной обработки
				$event->handle(); 																// Обработка события бота
			}
			else{
				// Если интеграция, то заносим команды в специальный файл
				$integration_data = implode(';', $event->getTextMessageCommandList());
				$integration_data .= "\n" . implode(';', $event->getCallbackButtonCommandList());
				$integration_data .= "\n" . implode(';', $event->getTextButtonCommandList());
				file_put_contents(BOTPATH_TMP . '/php_integration.txt', $integration_data);
			}
			$event->exit(); 																	// Очищение памяти
		}
	}

	//////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
	//////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

	// Legacy Module
	class BotModule
	{
		private $messagesModule;

		public function __construct($db = null)
		{
			$this->messagesModule = new Bot\Messages($db);
		}

		public function buildVKSciptAppealByID($user_id, $varname = "appeal")
		{ // Создание переменной appeal с обращением к пользователю, посредством VKScript и vk_execute()
			return $this->messagesModule->buildVKSciptAppealByID($user_id, $varname);
		}

		function sendMessage($peer_id, $message, $from_id = null, $params = array())
		{ // Отправка сообщений
			$this->messagesModule->setAppealID($from_id);
			return $this->messagesModule->sendMessage($peer_id, "%appeal%{$message}", $params);
		}

		function editMessage($peer_id, $conversation_message_id, $from_id = null, $message, $params = array())
		{
			$this->messagesModule->setAppealID($from_id);
			return $this->messagesModule->editMessage($peer_id, $conversation_message_id, "%appeal%{$message}", $params);
		}

		function sendSilentMessage($peer_id, $message, $from_id = null, $params = array())
		{ // Отправка сообщений без упоминаний
			if (is_null($from_id))
				$appeal = "";
			else
				$appeal = "%appeal%";
			$this->messagesModule->setAppealID($from_id);
			return $this->messagesModule->sendSilentMessage($peer_id, "{$appeal}{$message}", $params);
		}

		function sendSystemMsg_NoRights($data)
		{
			$this->messagesModule->setAppealID($data->object->from_id);
			return $this->messagesModule->sendSilentMessage($data->object->peer_id, Bot\Messages::MESSAGE_NO_RIGHTS);
		}

		function sendCommandListFromArray($data, $message = "", $list = array(), $keyboard = null)
		{ // Legacy
			$this->messagesModule->setAppealID($data->object->from_id);
			return $this->messagesModule->sendSilentMessageWithListFromArray($data->object->peer_id, "%appeal%{$message}", $list, $keyboard);
		}
	}

	class RandomOrg
	{ // Класс для работы с Random.org
		public static function generateIntegers($min, $max, $n, $id = 0, $replacement = true)
		{
			$options = array(
				'http' => array(
					'method'  => 'POST',
					'header'  => 'Content-type: application/json',
					'content' => json_encode(array(
						'jsonrpc' => '2.0',
						'method' => 'generateIntegers',
						'params' => array(
							'apiKey' => bot_getconfig('RANDOMORG_API_KEY'),
							'n' => $n,
							'min' => $min,
							'max' => $max,
							'replacement' => $replacement
						),
						'id' => $id
					))
				)
			);
			$recieved_data = file_get_contents('https://api.random.org/json-rpc/2/invoke', false, stream_context_create($options));
			if ($recieved_data !== false)
				return json_decode($recieved_data, true);
			return false;
		}
	}

	class GameController
	{
		const GAME_SESSIONS_DIRECTORY = BOTPATH_DATA . "/game_sessions";

		private static function initGameSessionsDirectory()
		{
			if (!file_exists(self::GAME_SESSIONS_DIRECTORY))
				mkdir(self::GAME_SESSIONS_DIRECTORY);
		}

		public static function getSession($chat_id)
		{
			self::initGameSessionsDirectory();
			if (file_exists(self::GAME_SESSIONS_DIRECTORY . "/chat{$chat_id}.json")) {
				$data = json_decode(file_get_contents(self::GAME_SESSIONS_DIRECTORY . "/chat{$chat_id}.json"), true);
				if ($data !== false)
					return (object) $data;
			}
			return false;
		}

		public static function setSession($chat_id, $id, $object)
		{
			self::initGameSessionsDirectory();
			if (file_exists(self::GAME_SESSIONS_DIRECTORY . "/chat{$chat_id}.json")) {
				$data = json_decode(file_get_contents(self::GAME_SESSIONS_DIRECTORY . "/chat{$chat_id}.json"), true);
				if ($data !== false && $data["id"] == $id) {
					$data["object"] = $object;
					if (file_put_contents(self::GAME_SESSIONS_DIRECTORY . "/chat{$chat_id}.json", json_encode($data, JSON_UNESCAPED_UNICODE)) === false)
						return false;
					else
						return true;
				} else {
					return false;
				}
			} else {
				$data = array(
					'id' => $id,
					'object' => $object
				);
				if (file_put_contents(self::GAME_SESSIONS_DIRECTORY . "/chat{$chat_id}.json", json_encode($data, JSON_UNESCAPED_UNICODE)) === false)
					return false;
				else
					return true;
			}
		}

		public static function deleteSession($chat_id, $id)
		{
			self::initGameSessionsDirectory();
			if (file_exists(self::GAME_SESSIONS_DIRECTORY . "/chat{$chat_id}.json")) {
				$data = json_decode(file_get_contents(self::GAME_SESSIONS_DIRECTORY . "/chat{$chat_id}.json"), true);
				if ($data !== false && $data["id"] == $id)
					return unlink(self::GAME_SESSIONS_DIRECTORY . "/chat{$chat_id}.json");
			}
			return false;
		}
	}

	// Инициализация команд
	function bot_initcmd($event)
	{
		// Игнорирование отсутствие базы данных для следующих команд

		// Основное
		//$event->addTextMessageCommand("!cmdlist", 'bot_cmdlist');
		$event->addTextMessageCommand("!reg", 'bot_register', ['ignore_db' => true]);
		$event->addTextMessageCommand("!чат", 'bot_chatinfo');

		// Система управления беседой
		$event->addTextMessageCommand("!меню", 'bot_menu_tc');

		// Прочее
		$event->addTextMessageCommand("!лайк", 'bot_like_handler');
		$event->addTextMessageCommand("!убрать", 'bot_remove_handler');
		$event->addTextMessageCommand("!id", 'bot_getid');
		$event->addTextMessageCommand("!крестики-нолики", 'bot_tictactoe');

		// Многословные команды
		$event->addTextMessageCommand("пожать", "bot_shakecmd");
		$event->addTextMessageCommand("дать", "bot_givecmd");

		// Обработчик для запуска текстовых команд из под аргумента кнопки
		$event->addTextButtonCommand("bot_runtc", 'bot_keyboard_rtct_handler'); // Запуск текстовых команд из под Text-кнопки

		// Callback-кнопки
		$event->addCallbackButtonCommand("bot_menu", 'bot_menu_cb');
		$event->addCallbackButtonCommand('bot_tictactoe', 'bot_tictactoe_cb');
		$event->addCallbackButtonCommand('bot_reg', 'bot_register_cb', ['ignore_db' => true]);
		$event->addCallbackButtonCommand('bot_run', 'bot_run_cb', ['ignore_db' => true]);
	}

	function bot_register($finput)
	{ // Регистрация чата
		// Инициализация базовых переменных
		$data = $finput->data;
		$argv = $finput->argv;
		$db = $finput->db;

		$messagesModule = new Bot\Messages($db);
		if (!$db->isExists()) {
			$response = json_decode(vk_execute($messagesModule->buildVKSciptAppealByID($data->object->from_id) . bot_test_rights_exe($data->object->peer_id, $data->object->from_id, "API.messages.send({'peer_id':{$data->object->peer_id},'message':appeal+', &#9940;У вас нет прав для этой команды.','disable_mentions':true});return 0;", true) . "var chat=API.messages.getConversationsById({'peer_ids':[{$data->object->peer_id}],'extended':1}).items[0];
				if(chat.peer.type!='chat'){API.messages.send({'peer_id':{$data->object->peer_id},'message':appeal+', эта беседа не является групповым чатом.','disable_mentions':true});return{'result':0};}API.messages.send({'peer_id':{$data->object->peer_id},'message':appeal+', ✅Беседа успешно зарегистрирована.','disable_mentions':true});return 1;"))->response;
			if ($response == 1) {
				$chat_id = $data->object->peer_id - 2000000000;
				$bulk = new MongoDB\Driver\BulkWrite;
				$bulk->update(['_id' => $db->getDocumentID()], ['$set' => ['chat_id' => $chat_id, 'owner_id' => $data->object->from_id]], ['upsert' => true]);
				$db->executeBulkWrite($bulk);
			}
		} else {
			$msg = ", данная беседа уже зарегистрирована.";
			vk_execute($messagesModule->buildVKSciptAppealByID($data->object->from_id) . "return API.messages.send({'peer_id':{$data->object->peer_id}, 'message':appeal+'{$msg}','disable_mentions':true});");
		}
	}

	function bot_register_cb($finput)
	{ // Регистрация чата
		// Инициализация базовых переменных
		$data = $finput->data;
		$payload = $finput->payload;
		$db = $finput->db;

		$messagesModule = new Bot\Messages($db);
		if (!$db->isExists()) {
			$snackbar1_json = json_encode(array('event_id' => $data->object->event_id, 'user_id' => $data->object->user_id, 'peer_id' => $data->object->peer_id, 'event_data' => json_encode(array('type' => 'show_snackbar', 'text' => "&#9940; У вас нет прав для этой команды."), JSON_UNESCAPED_UNICODE)));
			$snackbar2_json = json_encode(array('event_id' => $data->object->event_id, 'user_id' => $data->object->user_id, 'peer_id' => $data->object->peer_id, 'event_data' => json_encode(array('type' => 'show_snackbar', 'text' => "&#9940; Эта беседа не является групповым."), JSON_UNESCAPED_UNICODE)));
			$response = json_decode(vk_execute($messagesModule->buildVKSciptAppealByID($data->object->user_id) . bot_test_rights_exe($data->object->peer_id, $data->object->user_id, "API.messages.sendMessageChatEventAnswer({$snackbar1_json});return 0;", true) . "var chat=API.messages.getConversationsById({'peer_ids':[{$data->object->peer_id}],'extended':1}).items[0];
				if(chat.peer.type!='chat'){API.messages.sendMessageChatEventAnswer({$snackbar2_json});return 0;}API.messages.edit({'peer_id':{$data->object->peer_id},'conversation_message_id':{$data->object->conversation_message_id},'message':appeal+', ✅Беседа успешно зарегистрирована.','disable_mentions':true});return 1;"))->response;
			if ($response == 1) {
				$chat_id = $data->object->peer_id - 2000000000;
				$bulk = new MongoDB\Driver\BulkWrite;
				$bulk->update(['_id' => $db->getDocumentID()], ['$set' => ['chat_id' => $chat_id, 'owner_id' => $data->object->user_id]], ['upsert' => true]);
				$db->executeBulkWrite($bulk);
			}
		} else
			bot_show_snackbar($data->object->event_id, $data->object->user_id, $data->object->peer_id, '&#9940; Данная беседа уже зарегистрирована.');
	}


	function bot_parse_argv(string $text)
	{
		$text = addcslashes($text, "\n");
		$argv = array();
		foreach (str_getcsv($text, ' ') as $v) {
			if ($v != "")
				$argv[] = stripcslashes($v);
		}
		return $argv;
	}

	function bot_get_text_by_argv(array $argv, int $start, int $end = 0)
	{
		$argv_end = count($argv) - 1;
		if ($end <= 0 || $end > $argv_end)
			$end = $argv_end;
		elseif ($start > $argv_end)
			return false;
		$text_array = [];
		for ($i = $start; $i <= $end; $i++)
			$text_array[] = $argv[$i];
		return implode(' ', $text_array);
	}

	function bot_pre_handle($event)
	{
		$db = $event->getDatabase();
		$data = $event->getData();

		if ($data->object->peer_id > 2000000000) {
			if ($db->isExists()) {
				switch ($data->type) {
					case 'message_new':
						// Антифлуд
						if (AntiFlood::handler($data, $db, $event->getChatModes(), $event->getPermissionSystem())) {
							$event->exit();
							exit;
						}

						// Статистика
						break;

					case 'message_event':
						break;
				}
			} /*else
				bot_send_first_invite_message($event);				// Вывод первого сообщение, когда добавляют незарегистрированного бота
				*/
		}
	}

	// Функция для отправки Snackbar'а
	function bot_show_snackbar($event_id, $user_id, $peer_id, $text)
	{
		return vk_call('messages.sendMessageEventAnswer', array('event_id' => $event_id, 'user_id' => $user_id, 'peer_id' => $peer_id, 'event_data' => json_encode(array('type' => 'show_snackbar', 'text' => $text), JSON_UNESCAPED_UNICODE)));
	}

	function bot_get_userid_by_nick($db, $nick, &$id)
	{
		$nicknames = (array) $db->executeQuery(new \MongoDB\Driver\Query(['_id' => $db->getDocumentID()], ['projection' => ['_id' => 0, "chat_settings.user_nicknames" => 1]]))->getValue([0, "chat_settings", "user_nicknames"], []);
		foreach ($nicknames as $key => $value) {
			$nicknames[$key] = mb_strtolower($value);
		}
		$id_key = array_search(mb_strtolower($nick), $nicknames);
		if ($id_key !== false) {
			$id = intval(mb_substr($id_key, 2));
			return true;
		} else
			return false;
	}

	function bot_get_userid_by_mention($mention, &$id)
	{
		$mention_len = mb_strlen($mention);
		if (mb_substr($mention, 0, 3) == "[id" && mb_substr($mention, $mention_len - 1, $mention_len - 1) == "]") {
			$mention_parts = explode('|', mb_substr($mention, 3, $mention_len));
			if (count($mention_parts) >= 2) {
				$id = intval($mention_parts[0]);
				return true;
			}
		}
		return false;
	}

	function bot_test_rights_exe($peer_id, $member_id, $action_code, $check_owner = false)
	{ // Тестирование прав через VKScript
		$code = "var members=API.messages.getConversationMembers({'peer_id':{$peer_id}});var member={};var i=0;while(i<members.items.length){if(members.items[i].member_id=={$member_id}){member=members.items[i];i=members.items.length;};i=i+1;};";
		if ($check_owner)
			$code .= "if(!member.is_owner){{$action_code}}";
		else
			$code .= "if(!member.is_admin){{$action_code}}";
		return $code;
	}

	function bot_int_to_emoji_str($number)
	{
		$array = array();
		while ($number > 0) {
			$array[] = $number % 10;
			$number = intval($number / 10);
		}
		$array = array_reverse($array);

		$emoji = array('0&#8419;', '1&#8419;', '2&#8419;', '3&#8419;', '4&#8419;', '5&#8419;', '6&#8419;', '7&#8419;', '8&#8419;', '9&#8419;');

		$string = "";

		foreach ($array as $key => $value) {
			$string .= $emoji[$value];
		}

		return $string;
	}

	/////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
	// Прочее
	/////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

	function bot_get_array_value($array, $index, $default = null)
	{ // Будут баги, изменить null на ""
		if (array_key_exists($index, $array))
			return $array[$index];
		else
			return $default;
	}

	function bot_message_not_reg($data)
	{
		$messagesModule = new Bot\Messages();
		$keyboard = vk_keyboard_inline([[vk_callback_button("Зарегистировать", ['bot_reg'], 'positive')]]);
		if ($data->type == 'message_new') {
			$messagesModule->sendSilentMessage($data->object->peer_id, "✅Для работы бота нажмите \"Зарегистировать\".\n❗Для нормального функционирование необходимо выдать боту статус администратора в беседе.", ['keyboard' => $keyboard]);
		} else if ($data->type == 'message_event')
			bot_show_snackbar($data->object->event_id, $data->object->user_id, $data->object->peer_id, "⛔ Беседа не зарегистрирована.");
	}

	function bot_getconfig($name)
	{
		return Bot\Config::get($name);
	}

	function bot_chatinfo($finput)
	{
		// Инициализация базовых переменных
		$data = $finput->data;
		$argv = $finput->argv;
		$db = $finput->db;

		$messagesModule = new Bot\Messages($db);

		$query = new MongoDB\Driver\Query(['_id' => $db->getDocumentID()], ['projection' => [
			'_id' => 0,
			'chat_id' => 1,
			'owner_id' => 1,
			'chat_settings.chat_modes' => 1
		]]);
		$extractor = $db->executeQuery($query);

		// Необходимые переменные
		$chat_id = $extractor->getValue([0, "chat_id"]);
		$owner_id = $extractor->getValue([0, "owner_id"]);
		$chat_modes = $extractor->getValue([0, "chat_settings", "chat_modes"], []);

		$main_info = "🆔ID чата: {$chat_id}\n👤Владелец: %OWNER%";

		$chat_modes_info = "⚙Режимы:";
		foreach (ChatModes::getDefaultModeList() as $key => $value) {
			if (array_key_exists($key, $chat_modes)) {
				if ($chat_modes->$key)
					$chat_modes_info .= "\n&#12288;✅{$value["label"]}";
				else
					$chat_modes_info .= "\n&#12288;⛔{$value["label"]}";
			} else {
				if ($value['default_state'])
					$chat_modes_info .= "\n&#12288;✅{$value["label"]}";
				else
					$chat_modes_info .= "\n&#12288;⛔{$value["label"]}";
			}
		}

		$json_insert = json_encode(['m' => ", Информация:\n{$main_info}\n{$chat_modes_info}"], JSON_UNESCAPED_UNICODE);
		$json_insert = vk_parse_var($json_insert, 'OWNER');

		vk_execute($messagesModule->buildVKSciptAppealByID($data->object->from_id) . "var user=API.users.get({user_ids:[{$owner_id}]})[0];var OWNER=\"@id{$owner_id} (\"+user.first_name.substr(0, 2)+\". \"+user.last_name+\")\";var in={$json_insert};API.messages.send({peer_id:{$data->object->peer_id},message:appeal+in.m,disable_mentions:true});");
	}

	function bot_keyboard_remove($data)
	{
		$keyboard = vk_keyboard(false, array());
		$messagesModule = new Bot\Messages();
		$messagesModule->sendSilentMessage($data->object->peer_id, '✅Клавиатура убрана.', array('keyboard' => $keyboard));
	}

	function bot_like_handler($finput)
	{
		// Инициализация базовых переменных
		$data = $finput->data;
		$argv = $finput->argv;
		$db = $finput->db;

		if (array_key_exists(1, $argv))
			$command = mb_strtolower($argv[1]);
		else
			$command = "";
		if ($command == "аву")
			fun_like_avatar($data, $db);
		else {
			$commands = array(
				'Лайк аву - Лайкает аву'
			);

			$messagesModule = new Bot\Messages($db);
			$messagesModule->setAppealID($data->object->from_id);
			$messagesModule->sendSilentMessageWithListFromArray($data->object->peer_id, '%appeal%, используйте:', $commands);
		}
	}

	function bot_remove_handler($finput)
	{
		// Инициализация базовых переменных
		$data = $finput->data;
		$argv = $finput->argv;
		$db = $finput->db;

		if (array_key_exists(1, $argv))
			$command = mb_strtolower($argv[1]);
		else
			$command = "";
		if ($command == "кнопки")
			bot_keyboard_remove($data);
		elseif ($command == "ник")
			manager_remove_nick($data, $db, $finput);
		else {
			$commands = array(
				'!убрать кнопки - Убирает кнопки',
				'!убрать ник - Убирает ник пользователя'
			);

			$messagesModule = new Bot\Messages($db);
			$messagesModule->setAppealID($data->object->from_id);
			$messagesModule->sendSilentMessageWithListFromArray($data->object->peer_id, '%appeal%, используйте:', $commands);
		}
	}

	function bot_getid($finput)
	{
		// Инициализация базовых переменных
		$data = $finput->data;
		$argv = $finput->argv;
		$db = $finput->db;

		$member_id = 0;

		$messagesModule = new Bot\Messages($db);
		$messagesModule->setAppealID($data->object->from_id);

		if (array_key_exists(0, $data->object->fwd_messages)) {
			$member_id = $data->object->fwd_messages[0]->from_id;
		} elseif (array_key_exists(1, $argv)) {
			if (!bot_get_userid_by_mention($argv[1], $member_id))
				bot_get_userid_by_nick($db, $argv[1], $member_id);
		} else {
			$messagesModule->sendSilentMessage($data->object->peer_id, "%appeal%, Ваш ID: {$data->object->from_id}.");
			return;
		}

		$messagesModule->sendSilentMessage($data->object->peer_id, "%appeal%, ID: {$member_id}.");
	}

	function bot_keyboard_rtcc_handler($finput)
	{
		// Инициализация базовых переменных
		$data = $finput->data;
		$payload = $finput->payload;
		$db = $finput->db;

		if (property_exists($payload, "text_command") && gettype($payload->text_command) == "string") {
			$modified_data = (object) array(
				'type' => 'message_new',
				'object' => (object) array(
					'date' => time(),
					'from_id' => $data->object->user_id,
					'id' => 0,
					'out' => 0,
					'peer_id' => $data->object->peer_id,
					'text' => $payload->text_command,
					'conversation_message_id' => $data->object->conversation_message_id,
					'fwd_messages' => array(),
					'important' => false,
					'random_id' => 0,
					'attachments' => array(),
					'is_hidden' => false
				)
			);
			$finput->event->runTextCommand($modified_data);
		}
	}

	function bot_keyboard_rtct_handler($finput)
	{
		// Инициализация базовых переменных
		$data = $finput->data;
		$payload = $finput->payload;
		$db = $finput->db;

		if (property_exists($payload, "text_command") && gettype($payload->text_command) == "string") {
			$modified_data = $data;
			$modified_data->object->text = $payload->text_command;
			unset($modified_data->object->payload);
			$finput->event->runTextCommand($modified_data);
		}
	}

	function bot_send_first_invite_message($event)
	{
		// Инициализация базовых переменных
		$data = $event->getData();

		if (property_exists($data->object, 'action') && $data->object->action->type == 'chat_invite_user' && $data->object->action->member_id == -bot_getconfig('VK_GROUP_ID')) {
			bot_message_not_reg($data);
			return true;
		}
		return false;
	}

	function bot_message_action_handler($finput)
	{
		// Инициализация базовых переменных
		$data = $finput->data;
		$db = $finput->db;

		if (property_exists($data->object, 'action')) {
			if ($data->object->action->type == "chat_kick_user") {
				if ($data->object->action->member_id == $data->object->from_id) {
					$chat_id = $data->object->peer_id - 2000000000;
					$permissionSystem = $finput->event->getPermissionSystem();
					if (!$permissionSystem->checkUserPermission($data->object->action->member_id, 'prohibit_autokick')) { // Проверка ранга (Президент)
						vk_execute("var user=API.users.get({'user_ids':[{$data->object->from_id}]})[0];var msg='Пока, @id{$data->object->from_id} ('+user.first_name+' '+user.last_name+'). Больше ты сюда не вернешься!';API.messages.send({'peer_id':{$data->object->peer_id}, 'message':msg});API.messages.removeChatUser({'chat_id':{$chat_id},'user_id':{$data->object->action->member_id}});return 'ok';");
						return true;
					}
				} else {
					vk_execute("var user=API.users.get({'user_ids':[{$data->object->action->member_id}],'fields':'sex'})[0];var msg='';if(user.sex==1){msg='Правильно, она мне никогда не нравилась.';}else{msg='Правильно, он мне никогда не нравился.';}API.messages.send({'peer_id':{$data->object->peer_id},'message':msg});");
					return true;
				}
			} elseif ($data->object->action->type == "chat_invite_user") {
				$messagesModule = new Bot\Messages($db);
				if ($data->object->action->member_id == -bot_getconfig('VK_GROUP_ID')) {
					$messagesModule->sendSilentMessage($data->object->peer_id, "О, привет!");
					return true;
				} else {
					$banned_users = BanSystem::getBanList($db);
					$isBanned = false;
					foreach ($banned_users as $banned_user) {
						if ($banned_user->user_id == $data->object->action->member_id) {
							$chat_id = $data->object->peer_id - 2000000000;
							$permissionSystem = $finput->event->getPermissionSystem();
							if ($permissionSystem->checkUserPermission($data->object->from_id, 'manage_punishments')) { // Проверка ранга (Президент)
								vk_execute("API.messages.send({'peer_id':{$data->object->peer_id},'message':'@id{$data->object->action->member_id} (Пользователь) был приглашен @id{$data->object->from_id} (администратором) беседы и автоматически разбанен.'});");
								BanSystem::unbanUser($db, $data->object->action->member_id);
							} else {
								$ban_info = BanSystem::getUserBanInfo($db, $data->object->action->member_id);
								json_decode(vk_execute($messagesModule->buildVKSciptAppealByID($data->object->action->member_id) . "API.messages.send({'peer_id':{$data->object->peer_id}, 'message':appeal+', вы забанены в этой беседе!\\nПричина: {$ban_info["reason"]}.'});API.messages.removeChatUser({'chat_id':{$chat_id},'user_id':{$data->object->action->member_id}});"));
								$isBanned = true;
							}
						}
					}
					if (!$isBanned)
						manager_show_invited_greetings($data, $db);
					return true;
				}
			}
		}
		return false;
	}

	function bot_tictactoe($finput)
	{
		// Инициализация базовых переменных
		$data = $finput->data;
		$argv = $finput->argv;
		$db = $finput->db;

		$messagesModule = new Bot\Messages();

		$chatModes = $finput->event->getChatModes();
		if (!$chatModes->getModeValue("games_enabled")) { // Отключаем, если в беседе запрещены игры
			$messagesModule->setAppealID($data->object->from_id);
			$messagesModule->sendSilentMessage($data->object->peer_id, "%appeal%, ⛔В чате отключены игры!");
			return;
		}

		$keyboard = vk_keyboard_inline(array(
			array(vk_callback_button("Играть", array('bot_tictactoe', 10, 0, 0), 'primary')),
			array(vk_callback_button("Закрыть", array('bot_tictactoe', 0), 'negative'))
		));

		$messagesModule->sendSilentMessage($data->object->peer_id, "Крестик-нолики. Чтобы присоединиться, нажмите кнопку \"Играть.\"\n\nИгрок 1: Отсутствует\nИгрок 2: Отсутствует", array('keyboard' => $keyboard));
	}

	function bot_tictactoe_cb($finput)
	{
		// Инициализация базовых переменных
		$data = $finput->data;
		$payload = $finput->payload;
		$db = $finput->db;

		if ($payload[1] == 0) {
			vk_call('messages.edit', array(
				'peer_id' => $data->object->peer_id,
				'conversation_message_id' => $data->object->conversation_message_id,
				'message' => 'Игра остановлена.'
			));
		} elseif ($payload[1] == 10) {
			$chatModes = $finput->event->getChatModes();
			if (!$chatModes->getModeValue("games_enabled")) { // Отключаем, если в беседе запрещены игры
				bot_show_snackbar($data->object->event_id, $data->object->user_id, $data->object->peer_id, '⛔ В чате отключены игры!');
				return;
			}

			$player1 = bot_get_array_value($payload, 2, 0);
			$player2 = bot_get_array_value($payload, 3, 0);
			$messageUpdateRequired = false;
			$playButtonColor = "";
			if ($player1 == 0) {
				$player1 = $data->object->user_id;
				$messageUpdateRequired = true;
				$playButtonColor = "primary";
			} elseif ($player2 == 0) {
				if ($data->object->user_id != $player1) {
					$player2 = $data->object->user_id;
					$messageUpdateRequired = true;
					$playButtonColor = "positive";
				} else {
					bot_show_snackbar($data->object->event_id, $data->object->user_id, $data->object->peer_id, '⛔ Вы уже играете в этой партии!');
				}
			} else {
				$buttons = array(array());
				for ($i = 0; $i < 9; $i++) {
					$buttons[intdiv($i, 3)][$i % 3] = vk_callback_button('&#12288;', array('bot_tictactoe', $i + 1, $player1, $player2, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0), 'secondary');
				}
				$keyboard = vk_keyboard_inline($buttons);
				$insertedValues = json_encode(array(
					'player_move' => $player1,
					'keyboard' => $keyboard
				));
				vk_execute("var insertedValues={$insertedValues};var player_move=insertedValues.player_move;var player_data=API.users.get({'user_id':player_move})[0];var message='Ход: @id'+player_data.id+' ('+player_data.first_name+' '+player_data.last_name+')';API.messages.edit({'peer_id':{$data->object->peer_id},'conversation_message_id':{$data->object->conversation_message_id},'message':message,'keyboard':insertedValues.keyboard});");
			}

			if ($messageUpdateRequired) {
				$keyboard = vk_keyboard_inline(array(
					array(vk_callback_button("Играть", array('bot_tictactoe', 10, $player1, $player2), $playButtonColor)),
					array(vk_callback_button("Закрыть", array('bot_tictactoe', 0), 'negative'))
				));

				$insertedValues = json_encode(array(
					'player1' => $player1,
					'player2' => $player2,
					'keyboard' => $keyboard
				), JSON_UNESCAPED_UNICODE);

				vk_execute("var insertedValues={$insertedValues};var player1=insertedValues.player1;var player2=insertedValues.player2;var players=API.users.get({'user_ids':[player1,player2]});var message='Крестик-нолики. Чтобы присоединиться, нажмите кнопку \"Играть.\"\\n\\n';if(player1!=0){message=message+'Игрок 1: @id'+players[0].id+' ('+players[0].first_name+' '+players[0].last_name+')\\n';}else{message=message+'Игрок 1: Отсутствует\\n';}if(player2!=0){message=message+'Игрок 2: @id'+players[1].id+' ('+players[1].first_name+' '+players[1].last_name+')\\n';}else{message=message+'Игрок 2: Отсутствует\\n';}API.messages.edit({'peer_id':{$data->object->peer_id},'conversation_message_id':{$data->object->conversation_message_id},'message':message,'keyboard':insertedValues.keyboard});");
			}
		} elseif ($payload[1] >= 1 && $payload[1] <= 9) {
			$chatModes = $finput->event->getChatModes();
			if (!$chatModes->getModeValue("games_enabled")) { // Отключаем, если в беседе запрещены игры
				bot_show_snackbar($data->object->event_id, $data->object->user_id, $data->object->peer_id, '⛔ В чате отключены игры!');
				return;
			}

			if ($payload[2 + $payload[4]] == $data->object->user_id) {
				if ($payload[4 + $payload[1]] == 0) {
					$payload[4 + $payload[1]] = $payload[4] + 1;

					for ($i = 1; $i <= 2; $i++) {
						// 05 06 07
						// 08 09 10
						// 11 12 13
						if ($payload[5] == $i && $payload[6] == $i && $payload[7] == $i) {
							$winner = $i;
							break;
						}
						if ($payload[8] == $i && $payload[9] == $i && $payload[10] == $i) {
							$winner = $i;
							break;
						} elseif ($payload[11] == $i && $payload[12] == $i && $payload[13] == $i) {
							$winner = $i;
							break;
						} elseif ($payload[5] == $i && $payload[8] == $i && $payload[11] == $i) {
							$winner = $i;
							break;
						} elseif ($payload[6] == $i && $payload[9] == $i && $payload[12] == $i) {
							$winner = $i;
							break;
						} elseif ($payload[7] == $i && $payload[10] == $i && $payload[13] == $i) {
							$winner = $i;
							break;
						} elseif ($payload[5] == $i && $payload[9] == $i && $payload[13] == $i) {
							$winner = $i;
							break;
						} elseif ($payload[7] == $i && $payload[9] == $i && $payload[11] == $i) {
							$winner = $i;
							break;
						}
					}

					if (isset($winner)) {
						$game_result = "";
						for ($i = 0; $i < 9; $i++) {
							switch ($payload[5 + $i]) {
								case 1:
									$symbol = '&#10060; ';
									break;

								case 2:
									$symbol = '&#11093; ';
									break;

								default:
									$symbol = '&#12288; ';
									break;
							}
							$game_result .= $symbol;
							if (($i + 1) % 3 == 0)
								$game_result .= "\n";
						}
						$keyboard = vk_keyboard_inline(array(
							array(vk_callback_button("Играть снова", array('bot_tictactoe', 10), "positive")),
							array(vk_callback_button("Закрыть", array('bot_tictactoe', 0), 'negative'))
						));
						$insertedValues = json_encode(array(
							'player' => $payload[1 + $winner],
							'keyboard' => $keyboard,
							'game_result' => $game_result
						));
						vk_execute("var insertedValues={$insertedValues};var player=insertedValues.player;var player_data=API.users.get({'user_id':player})[0];var message='Победил игрок: @id'+player_data.id+' ('+player_data.first_name+' '+player_data.last_name+')\\nРезультат:\\n'+insertedValues.game_result;API.messages.edit({'peer_id':{$data->object->peer_id},'conversation_message_id':{$data->object->conversation_message_id},'message':message,'keyboard':insertedValues.keyboard});");
					} else {
						$isCanMove = false;

						if ($payload[4] == 0) {
							$payload[4] = 1;
						} else {
							$payload[4] = 0;
						}

						$buttons = array(array());
						$symbol = '';
						$isCanMove = false;
						for ($i = 0; $i < 9; $i++) {
							switch ($payload[5 + $i]) {
								case 1:
									$symbol = '❌';
									break;

								case 2:
									$symbol = '⭕';
									break;

								default:
									$symbol = '&#12288;';
									$isCanMove = true;
									break;
							}
							$buttons[intdiv($i, 3)][$i % 3] = vk_callback_button($symbol, array('bot_tictactoe', $i + 1, $payload[2], $payload[3], $payload[4], $payload[5], $payload[6], $payload[7], $payload[8], $payload[9], $payload[10], $payload[11], $payload[12], $payload[13]), 'secondary');
						}

						if ($isCanMove) {
							$keyboard = vk_keyboard_inline($buttons);
							$insertedValues = json_encode(array(
								'player_move' => $payload[2 + $payload[4]],
								'keyboard' => $keyboard
							));
							vk_execute("var insertedValues={$insertedValues};var player_move=insertedValues.player_move;var player_data=API.users.get({'user_id':player_move})[0];var message='Ход: @id'+player_data.id+' ('+player_data.first_name+' '+player_data.last_name+')';API.messages.edit({'peer_id':{$data->object->peer_id},'conversation_message_id':{$data->object->conversation_message_id},'message':message,'keyboard':insertedValues.keyboard});");
						} else {
							$keyboard = vk_keyboard_inline(array(
								array(vk_callback_button("Играть снова", array('bot_tictactoe', 10), "positive")),
								array(vk_callback_button("Закрыть", array('bot_tictactoe', 0), 'negative'))
							));
							$insertedValues = json_encode(array(
								'keyboard' => $keyboard
							));
							vk_execute("var insertedValues={$insertedValues};var message='Ничья.';API.messages.edit({'peer_id':{$data->object->peer_id},'conversation_message_id':{$data->object->conversation_message_id},'message':message,'keyboard':insertedValues.keyboard});");
						}
					}
				} else {
					bot_show_snackbar($data->object->event_id, $data->object->user_id, $data->object->peer_id, '⛔ Клетка №' . ($payload[1]) . ' уже занята!');
				}
			} else {
				bot_show_snackbar($data->object->event_id, $data->object->user_id, $data->object->peer_id, '⛔ Сейчас не ваш ход!');
			}
		} else
			bot_show_snackbar($data->object->event_id, $data->object->user_id, $data->object->peer_id, '⛔ Неизвестная команда!');
	}

	function bot_menu_tc($finput)
	{
		// Инициализация базовых переменных
		$data = $finput->data;
		$argv = $finput->argv;
		$db = $finput->db;

		$messagesModule = new Bot\Messages($db);
		$messagesModule->setAppealID($data->object->from_id);

		$keyboard = vk_keyboard_inline(array(
			array(vk_callback_button("Центральное Меню", array('bot_menu', $data->object->from_id), 'positive'))
		));
		$messagesModule->sendSilentMessage($data->object->peer_id, "%appeal%, Запустить Центральное Меню можно кнопкой ниже.", array('keyboard' => $keyboard));
	}

	function bot_shakecmd($finput)
	{
		$sub_command = mb_strtolower(bot_get_array_value($finput->argv, 1, ""));
		switch ($sub_command) {
			case 'руку':
				roleplay_shakehand($finput);
				break;

			default:
				$messagesModule = new Bot\Messages($finput->db);
				$messagesModule->setAppealID($finput->data->object->from_id);
				$messagesModule->sendSilentMessageWithListFromArray($finput->data->object->peer_id, "%appeal%,  используйте:", [
					'Пожать руку <пользователь> - Жмет руку пользователю'
				]);
				break;
		}
	}

	function bot_givecmd($finput)
	{
		$sub_command = mb_strtolower(bot_get_array_value($finput->argv, 1, ""));
		switch ($sub_command) {
			case 'пять':
				roleplay_highfive($finput);
				break;

			default:
				$messagesModule = new Bot\Messages($finput->db);
				$messagesModule->setAppealID($finput->data->object->from_id);
				$messagesModule->sendSilentMessageWithListFromArray($finput->data->object->peer_id, "%appeal%,  используйте:", [
					'Дать пять <пользователь> - Дать пять пользователю'
				]);
				break;
		}
	}

	function bot_run_cb($finput)
	{
		// Инициализация базовых переменных
		$data = $finput->data;
		$payload = $finput->payload;

		// Функция тестирования пользователя
		$testing_user_id = intval(bot_get_array_value($payload, 2, 0));
		if ($testing_user_id === 0 || $testing_user_id === $data->object->user_id) {
			$text_command = bot_get_array_value($payload, 1, '');

			$text_command_argv = bot_parse_argv($text_command);
			$command_data = $finput->event->isTextMessageCommand($text_command_argv[0]);
			if ($command_data === false)
				bot_show_snackbar($data->object->event_id, $data->object->user_id, $data->object->peer_id, '⛔ Неизвестная команда!');
			elseif (!$command_data->output_system)
				bot_show_snackbar($data->object->event_id, $data->object->user_id, $data->object->peer_id, '⛔ Команда не поддерживает OutputSystem!');
			elseif ($command_data->only_text_input)
				bot_show_snackbar($data->object->event_id, $data->object->user_id, $data->object->peer_id, '⛔ Команда поддерживает только текстовый ввод!');
			elseif ($text_command != "") {
				$modified_data = (object) [
					'type' => 'message_new',
					'object' => (object) [
						'date' => time(),
						'from_id' => $data->object->user_id,
						'peer_id' => $data->object->peer_id,
						'text' => $text_command,
						'conversation_message_id' => $data->object->conversation_message_id
					]
				];
				$output = new Bot\OutputSystem(Bot\OutputSystem::TYPE_MSG_EVENT, $data);
				$result = $finput->event->runTextCommand($modified_data, $output);
				if ($result->code == Bot\ChatEvent::COMMAND_RESULT_INVALID_DATA)
					bot_show_snackbar($data->object->event_id, $data->object->user_id, $data->object->peer_id, '⛔ Произошла внутренняя ошибка!');
			} else {
				bot_show_snackbar($data->object->event_id, $data->object->user_id, $data->object->peer_id, '⛔ Не указана команда!');
			}
		} elseif ($testing_user_id !== $data->object->user_id)
			bot_show_snackbar($data->object->event_id, $data->object->user_id, $data->object->peer_id, '⛔ У вас нет доступа к этому меню!');
	}

	function bot_menu_cb($finput)
	{
		// Инициализация базовых переменных
		$data = $finput->data;
		$payload = $finput->payload;
		$db = $finput->db;

		// Переменные для редактирования сообщения
		$keyboard_buttons = array();
		$message = "";

		// Функция тестирования пользователя
		$testing_user_id = bot_get_array_value($payload, 1, $data->object->user_id);
		if ($testing_user_id !== $data->object->user_id) {
			$permissionSystem = $finput->event->getPermissionSystem();
			if (!$permissionSystem->checkUserPermission($data->object->user_id, 'customize_chat')) { // Проверка разрешения
				bot_show_snackbar($data->object->event_id, $data->object->user_id, $data->object->peer_id, '⛔ У вас нет доступа к этому меню!');
				return;
			}
		}

		// Переменная команды меню
		$code = bot_get_array_value($payload, 2, 1);
		switch ($code) {
			case 0:
				$text = bot_get_array_value($payload, 3, false);
				if (gettype($text) == "string")
					$message = $text;
				else
					$message = "✅ Меню закрыто.";
				break;

			case 1:
				$list_number = bot_get_array_value($payload, 3, 1);
				$elements = array(); // Массив всех кнопок

				/////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
				/////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
				/// Элементы в меню

				$elements[] = vk_callback_button("Список команд", array('bot_cmdlist', $testing_user_id), 'primary');

				$chatModes = $finput->event->getChatModes();
				if ($chatModes->getModeValue("economy_enabled")) { // Проверка режима экономики
					$elements[] = vk_callback_button("Работа", array('economy_work', $testing_user_id), 'primary');
					$elements[] = vk_callback_button("Бизнес", array('economy_company', $testing_user_id), 'primary');
					$elements[] = vk_callback_button("Образование", array('economy_education', $testing_user_id), 'primary');
					$elements[] = vk_callback_button("Магазин", array('economy_shop', $testing_user_id), 'primary');
				}

				$permissionSystem = $finput->event->getPermissionSystem();
				if ($permissionSystem->checkUserPermission($data->object->user_id, 'customize_chat')) { // Проверка разрешения
					$elements[] = vk_callback_button("Режимы", array('manager_mode', $testing_user_id), 'primary');
				}

				/////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
				/////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

				$listBuiler = new Bot\ListBuilder($elements, 6);
				$build = $listBuiler->build($list_number);
				if ($build->result) {
					for ($i = 0; $i < count($build->list->out); $i++) {
						$keyboard_buttons[intdiv($i, 2)][$i % 2] = $build->list->out[$i];
					}

					if ($build->list->max_number > 1) {
						$list_buttons = array();
						if ($build->list->number != 1) {
							$previous_list = $build->list->number - 1;
							$emoji_str = bot_int_to_emoji_str($previous_list);
							$list_buttons[] = vk_callback_button("{$emoji_str} ⬅", array('bot_menu', $testing_user_id, 1, $previous_list), 'secondary');
						}
						if ($build->list->number != $build->list->max_number) {
							$next_list = $build->list->number + 1;
							$emoji_str = bot_int_to_emoji_str($next_list);
							$list_buttons[] = vk_callback_button("➡ {$emoji_str}", array('bot_menu', $testing_user_id, 1, $next_list), 'secondary');
						}
						$keyboard_buttons[] = $list_buttons;
					}
				} else {
					bot_show_snackbar($data->object->event_id, $data->object->user_id, $data->object->peer_id, "⛔ Внутренняя ошибка: Неверный номер списка.");
					return;
				}

				$keyboard_buttons[] = array(vk_callback_button("Закрыть", array('bot_menu', $testing_user_id, 0), 'negative'));
				$message = "%appeal%, Центральное Меню.";
				break;

			default:
				bot_show_snackbar($data->object->event_id, $data->object->user_id, $data->object->peer_id, "⛔ Internal error.");
				return;
				break;
		}

		$messagesModule = new Bot\Messages($db);
		$messagesModule->setAppealID($data->object->user_id);
		$keyboard = vk_keyboard_inline($keyboard_buttons);
		$messagesModule->editMessage($data->object->peer_id, $data->object->conversation_message_id, $message, array('keyboard' => $keyboard));
	}
}
