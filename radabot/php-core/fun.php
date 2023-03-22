<?php

// Инициализация команд

use Bot\Messages;

function fun_initcmd($event)
{
	$event->addTextMessageCommand("!выбери", 'fun_choose');
	$event->addTextMessageCommand("!сколько", 'fun_howmuch');
	$event->addTextMessageCommand("!инфа", "fun_info");
	$event->addTextMessageCommand("!rndwall", "fun_rndwall");
	$event->addTextMessageCommand("!memes", 'fun_memes_control_panel');
	$event->addTextMessageCommand("!бутылочка", 'fun_bottle');
	$event->addTextMessageCommand("!tts", 'fun_tts');
	$event->addTextMessageCommand("!say", "fun_say");
	$event->addTextMessageCommand("!брак", "fun_marriage");
	$event->addTextMessageCommand("!браки", "fun_show_marriage_list");
	$event->addTextMessageCommand("!shrug", 'fun_shrug');
	$event->addTextMessageCommand("!tableflip", 'fun_tableflip');
	$event->addTextMessageCommand("!unflip", 'fun_unflip');
	$event->addTextMessageCommand("!кек", 'fun_kek');

	// Инициализация команд [кто/кого/кому]
	$event->addTextMessageCommand("!кто", 'fun_whois_nom');
	$event->addTextMessageCommand("!кого", 'fun_whois_acc');
	$event->addTextMessageCommand("!кому", 'fun_whois_dat');

	// Callback-кнопки
	$event->addCallbackButtonCommand("fun_memes", 'fun_memes_control_panel_cb');
}

function fun_kek($finput)
{
	// Инициализация базовых переменных
	$data = $finput->data;
	$argv = $finput->argv;
	$db = $finput->db;

	$messagesModule = new Bot\Messages($db);
	$messagesModule->setAppealID($data->object->from_id);

	$mode = bot_get_array_value($argv, 1, 1);

	if ($mode != 1 && $mode != 2) {
		$messagesModule->sendSilentMessageWithListFromArray($data->object->peer_id, "%appeal%, используйте:", [
			'!кек 1 - Кек слева направо',
			'!кек 2 - Кек справа налево'
		]);
		return;
	}

	$first_photo_id = -1;
	for ($i = 0; $i < count($data->object->attachments); $i++) {
		if ($data->object->attachments[$i]->type == "photo") {
			$first_photo_id = $i;
			break;
		}
	}
	if ($first_photo_id != -1) {
		$photo_sizes = $data->object->attachments[$first_photo_id]->photo->sizes;
		$photo_url_index = 0;
		for ($i = 0; $i < count($photo_sizes); $i++) {
			if ($photo_sizes[$i]->height > $photo_sizes[$photo_url_index]->height) {
				$photo_url_index = $i;
			}
		}
		$photo_url = $photo_sizes[$photo_url_index]->url;
		$path = BOTPATH_TMP . "/photo" . mt_rand(0, 65535) . ".jpg";
		file_put_contents($path, file_get_contents($photo_url));		// Загрузка фото

		// Обработка фотографии
		$im = imagecreatefromjpeg($path);
		$im_width = imagesx($im);
		$im_height = imagesy($im);
		switch ($mode) {
			case 2:
				$im2_width = ceil($im_width / 2);
				$im2 = imagecrop($im, ['x' => $im2_width - 1, 'y' => 0, 'width' => $im2_width, 'height' => $im_height]);
				$im = imagecreatetruecolor($im2_width * 2, $im_height);
				imagecopy($im, $im2, $im2_width, 0, 0, 0, $im2_width, $im_height);
				imageflip($im2, IMG_FLIP_HORIZONTAL);
				imagecopy($im, $im2, 0, 0, 0, 0, $im2_width, $im_height);
				break;

			default:
				$im2_width = ceil($im_width / 2);
				$im2 = imagecrop($im, ['x' => 0, 'y' => 0, 'width' => $im2_width, 'height' => $im_height]);
				$im = imagecreatetruecolor($im2_width * 2, $im_height);
				imagecopy($im, $im2, 0, 0, 0, 0, $im2_width, $im_height);
				imageflip($im2, IMG_FLIP_HORIZONTAL);
				imagecopy($im, $im2, $im2_width, 0, 0, 0, $im2_width, $im_height);
				break;
		}
		imagejpeg($im, $path);
		imagedestroy($im);
		imagedestroy($im2);

		$res1 =  json_decode(vk_execute($messagesModule->buildVKSciptAppealByID($data->object->from_id) . "return API.photos.getMessagesUploadServer({'peer_id':{$data->object->peer_id}});"));
		$res2 = json_decode(vk_uploadDocs(array('photo' => new CURLFile($path)), $res1->response->upload_url));
		$file_json = json_encode(array('photo' => $res2->photo, 'server' => $res2->server, 'hash' => $res2->hash));
		vk_execute($messagesModule->buildVKSciptAppealByID($data->object->from_id) . "var doc=API.photos.saveMessagesPhoto({$file_json});API.messages.send({'peer_id':{$data->object->peer_id},'message':appeal+', Кек:','attachment':'photo'+doc[0].owner_id+'_'+doc[0].id,'disable_mentions':true});");

		unlink($path);
	} else {
		$messagesModule->sendSilentMessage($data->object->peer_id, "%appeal%, &#9940;Фото не найдено!");
	}
}

function fun_memes_control_panel($finput)
{
	// Инициализация базовых переменных
	$data = $finput->data;
	$argv = $finput->argv;
	$db = $finput->db;
	$event = $finput->event;

	$botModule = new BotModule($db);

	$chatModes = $finput->event->getChatModes();
	if (!$chatModes->getModeValue("allow_memes")) { // Проверка режима
		$botModule->sendSilentMessage($data->object->peer_id, ", ⛔Панель управления мемами недоступна, так как в беседе отключен Режим allow_memes.", $data->object->from_id);
		return;
	}

	if (array_key_exists(1, $argv))
		$command = mb_strtolower($argv[1]);
	else
		$command = "";
	if ($command == "add") {
		$forbidden_names = array("%__appeal__%", "%__ownername__%", "-all", "%appeal%"); // Массив запрещенных наименований мемов
		$meme_name = mb_strtolower(bot_get_text_by_argv($argv, 2));
		if ($meme_name == "") {
			$botModule->sendSilentMessage($data->object->peer_id, ", &#9940;Не найдено название!", $data->object->from_id);
			return;
		}
		if (mb_substr_count($meme_name, '$') > 0) {
			$botModule->sendSilentMessage($data->object->peer_id, ", &#9940;Знак '\$' нельзя использовать.", $data->object->from_id);
			return;
		}
		for ($i = 0; $i < count($forbidden_names); $i++) { // Массив проверки имя на запрет
			if ($meme_name == $forbidden_names[$i]) {
				$botModule->sendSilentMessage($data->object->peer_id, ", &#9940;Данное имя нельзя использовать!", $data->object->from_id);
				return;
			}
		}
		if (mb_strlen($meme_name) > 15) {
			$botModule->sendSilentMessage($data->object->peer_id, ", &#9940;Имя не может быть больше 8 знаков!", $data->object->from_id);
			return;
		}
		$existing_meme = $db->executeQuery(new MongoDB\Driver\Query(['_id' => $db->getDocumentID()], ['projection' => ['_id' => 0, "fun.memes.{$meme_name}" => 1]]))->getValue("0.fun.memes.{$meme_name}", false);
		if ($existing_meme !== false) {
			$botModule->sendSilentMessage($data->object->peer_id, ", &#9940;Мем с таким именем уже существует!", $data->object->from_id);
			return;
		}

		$event_command_list = $event->getTextMessageCommandList();
		for ($i = 0; $i < count($event_command_list); $i++) { // Запрет на использование названий из Командной системы
			if ($meme_name == $event_command_list[$i]) {
				$botModule->sendSilentMessage($data->object->peer_id, ", &#9940;Данное имя нельзя использовать!", $data->object->from_id);
				return;
			}
		}

		if (count($data->object->attachments) == 0) {
			$botModule->sendSilentMessage($data->object->peer_id, ", &#9940;Вложения не найдены!", $data->object->from_id);
			return;
		}
		$content_attach = "";

		if ($data->object->attachments[0]->type == 'photo') {
			$photo_sizes = $data->object->attachments[0]->photo->sizes;
			$photo_url_index = 0;
			for ($i = 0; $i < count($photo_sizes); $i++) {
				if ($photo_sizes[$i]->height > $photo_sizes[$photo_url_index]->height) {
					$photo_url_index = $i;
				}
			}
			$photo_url = $photo_sizes[$photo_url_index]->url;
			$path = BOTPATH_TMP . "/photo" . mt_rand(0, 65535) . ".jpg";
			file_put_contents($path, file_get_contents($photo_url));
			$response =  json_decode(vk_execute($botModule->buildVKSciptAppealByID($data->object->from_id) . "return API.photos.getMessagesUploadServer({'peer_id':{$data->object->peer_id}});"));
			$res = json_decode(vk_uploadDocs(array('photo' => new CURLFile($path)), $response->response->upload_url));
			unlink($path);
			$res_json = json_encode(array('photo' => $res->photo, 'server' => $res->server, 'hash' => $res->hash));
			$photo = json_decode(vk_execute("return API.photos.saveMessagesPhoto({$res_json});
				"))->response[0];
			$content_attach = "photo{$photo->owner_id}_{$photo->id}";
		} elseif ($data->object->attachments[0]->type == 'audio') {
			$content_attach = "audio{$data->object->attachments[0]->audio->owner_id}_{$data->object->attachments[0]->audio->id}";
		} elseif ($data->object->attachments[0]->type == 'video') {
			if (property_exists($data->object->attachments[0]->video, "is_private") && $data->object->attachments[0]->video->is_private == 1) {
				$botModule->sendSilentMessage($data->object->peer_id, ", &#9940;Вложение является приватным!", $data->object->from_id);
				return;
			} else {
				$content_attach = "video{$data->object->attachments[0]->video->owner_id}_{$data->object->attachments[0]->video->id}";
			}
		} else {
			$botModule->sendSilentMessage($data->object->peer_id, ", &#9940;Тип вложения не поддерживается!", $data->object->from_id);
			return;
		}

		$meme = array(
			'owner_id' => $data->object->from_id,
			'content' => $content_attach,
			'date' => time()
		);
		$bulk = new MongoDB\Driver\BulkWrite;
		$bulk->update(['_id' => $db->getDocumentID()], ['$set' => ["fun.memes.{$meme_name}" => $meme]]);
		$db->executeBulkWrite($bulk);
		$botModule->sendSilentMessage($data->object->peer_id, ", ✅Мем сохранен!", $data->object->from_id);
	} elseif ($command == "del") {
		$meme_name = mb_strtolower(bot_get_text_by_argv($argv, 2));
		if ($meme_name == "") {
			$botModule->sendSilentMessage($data->object->peer_id, ", &#9940;Не найдено название!", $data->object->from_id);
			return;
		}

		if ($meme_name == "-all") {
			$permissionSystem = $finput->event->getPermissionSystem();
			if (!$permissionSystem->checkUserPermission($data->object->from_id, 'customize_chat')) { // Проверка разрешения
				$botModule->sendSilentMessage($data->object->peer_id, ", ⛔Вы не можете удалять мемы других пользователей.", $data->object->from_id);
				return;
			}

			json_decode(vk_execute($botModule->buildVKSciptAppealByID($data->object->from_id) . "API.messages.send({'peer_id':{$data->object->peer_id},'message':appeal+', ✅Все мемы в беседе были удалены!','disable_mentions':true});"))->response;

			$bulk = new MongoDB\Driver\BulkWrite;
			$bulk->update(['_id' => $db->getDocumentID()], ['$unset' => ["fun.memes" => 0]]);
			$db->executeBulkWrite($bulk);
		} else {
			$meme_owner = $db->executeQuery(new MongoDB\Driver\Query(['_id' => $db->getDocumentID()], ['projection' => ['_id' => 0, "fun.memes.{$meme_name}.owner_id" => 1]]))->getValue("0.fun.memes.{$meme_name}.owner_id", 0);
			if ($meme_owner == $data->object->from_id) {
				$bulk = new MongoDB\Driver\BulkWrite;
				$bulk->update(['_id' => $db->getDocumentID()], ['$unset' => ["fun.memes.{$meme_name}" => 0]]);
				$writeResult = $db->executeBulkWrite($bulk);

				if ($writeResult->getModifiedCount() > 0)
					$botModule->sendSilentMessage($data->object->peer_id, ", ✅Мем \"{$meme_name}\" удален!", $data->object->from_id);
				else
					$botModule->sendSilentMessage($data->object->peer_id, ", ⛔Мема с именем \"{$meme_name}\" не существует.", $data->object->from_id);
			} else {
				$permissionSystem = $finput->event->getPermissionSystem();
				if (!$permissionSystem->checkUserPermission($data->object->from_id, 'customize_chat')) { // Проверка разрешения
					$botModule->sendSilentMessage($data->object->peer_id, ", ⛔Вы не можете удалять мемы других пользователей.", $data->object->from_id);
					return;
				}

				$bulk = new MongoDB\Driver\BulkWrite;
				$bulk->update(['_id' => $db->getDocumentID()], ['$unset' => ["fun.memes.{$meme_name}" => 0]]);
				$writeResult = $db->executeBulkWrite($bulk);

				if ($writeResult->getModifiedCount() > 0)
					$botModule->sendSilentMessage($data->object->peer_id, ", ✅Мем \"{$meme_name}\" удален!", $data->object->from_id);
				else
					$botModule->sendSilentMessage($data->object->peer_id, ", ⛔Мема с именем \"{$meme_name}\" не существует.", $data->object->from_id);
			}
		}
	} elseif ($command == "list") {
		$meme_names = (array) $db->executeQuery(new MongoDB\Driver\Query(['_id' => $db->getDocumentID()], ['projection' => ['_id' => 0, "fun.memes" => 1]]))->getValue("0.fun.memes");
		$meme_names = array_keys($meme_names);
		if (count($meme_names) == 0) {
			$botModule->sendSilentMessage($data->object->peer_id, ", в беседе нет мемов.", $data->object->from_id);
			return;
		}
		$meme_str_list = "";
		for ($i = 0; $i < count($meme_names); $i++) {
			if ($meme_str_list == "")
				$meme_str_list = "[{$meme_names[$i]}]";
			else
				$meme_str_list = $meme_str_list . ", [{$meme_names[$i]}]";
		}
		$botModule->sendSilentMessage($data->object->peer_id, ", 📝список мемов в беседе:\n" . $meme_str_list, $data->object->from_id);
	} elseif ($command == "info") {
		$meme_name = mb_strtolower(bot_get_text_by_argv($argv, 2));

		if ($meme_name == "") {
			$botModule->sendSilentMessage($data->object->peer_id, ", ⛔введите имя мема.", $data->object->from_id);
			return;
		}

		$memes = $db->executeQuery(new MongoDB\Driver\Query(['_id' => $db->getDocumentID()], ['projection' => ['_id' => 0, "fun.memes" => 1]]))->getValue("0.fun.memes", []);
		$memes = Database\CursorValueExtractor::objectToArray($memes);

		if (array_key_exists($meme_name, $memes)) {
			$added_time = gmdate("d.m.Y", $memes[$meme_name]["date"] + 10800);
			$msg = "%__APPEAL__%, информация о меме:\n✏Имя: {$meme_name}\n🤵Владелец: %__OWNERNAME__%\n📅Добавлен: {$added_time}\n📂Содержимое: ⬇️⬇️⬇️";
			$request_array = array('peer_id' => $data->object->peer_id, 'message' => $msg, 'attachment' => $memes[$meme_name]["content"], 'disable_mentions' => true);

			$meme_names = array_keys($memes);
			$meme_names_count = count($meme_names);
			if ($meme_names_count) {
				$index = array_search($meme_name, $meme_names);
				$previous_element = $index - 1;
				$next_element = $index + 1;
				if ($previous_element <= 0)
					$previous_element = $meme_names_count - 1;
				if ($next_element >= $meme_names_count)
					$next_element = 0;
				$previous_element_str = bot_int_to_emoji_str($previous_element + 1);
				$next_element_str = bot_int_to_emoji_str($next_element + 1);
				$request_array['keyboard'] = vk_keyboard_inline(array(
					array(
						vk_callback_button("{$previous_element_str}⬅", array("fun_memes", $data->object->from_id, 1, $previous_element), "secondary"),
						vk_callback_button("➡{$next_element_str}", array("fun_memes", $data->object->from_id, 1, $next_element), "secondary"),
					),
					array(vk_callback_button("Закрыть", array('bot_menu', $data->object->from_id, 0), "negative"))
				));
			}

			$request = json_encode($request_array, JSON_UNESCAPED_UNICODE);
			$request = vk_parse_vars($request, array("__OWNERNAME__", "__APPEAL__"));
			vk_execute($botModule->buildVKSciptAppealByID($data->object->from_id, '__APPEAL__') . "var owner = API.users.get({'user_ids':[{$memes[$meme_name]["owner_id"]}]})[0];var __OWNERNAME__ = '@id{$memes[$meme_name]["owner_id"]} ('+owner.first_name+' '+owner.last_name+')';return API.messages.send({$request});");
		} else {
			$botModule->sendSilentMessage($data->object->peer_id, ", ⛔мема с именем \"{$meme_name}\" не существует.", $data->object->from_id);
		}
	} else {
		$commands = array(
			'!memes list - Список мемов беседы',
			'!memes add <name> <attachment> - Добавление мема',
			'!memes del <name> - Удаление мема',
			'!memes del -all - Удаление всех мемов из беседы',
			'!memes info <name> - Информация о меме'
		);
		$botModule->sendCommandListFromArray($data, ", ⛔используйте:", $commands);
	}
}

function fun_memes_control_panel_cb($finput)
{
	// Инициализация базовых переменных
	$data = $finput->data;
	$payload = $finput->payload;
	$db = $finput->db;

	// Функция тестирования пользователя
	$testing_user_id = bot_get_array_value($payload, 1, $data->object->user_id);
	if ($testing_user_id !== $data->object->user_id) {
		bot_show_snackbar($data->object->event_id, $data->object->user_id, $data->object->peer_id, '⛔ У вас нет доступа к этому меню!');
		return;
	}

	$messagesModule = new Bot\Messages($db);

	$command = bot_get_array_value($payload, 2, 0);

	switch ($command) {
		case 1:
			$meme_index = bot_get_array_value($payload, 3, -1);

			if ($meme_index < 0) {
				bot_show_snackbar($data->object->event_id, $data->object->user_id, $data->object->peer_id, '⛔ Внутренняя ошибка: Неверно указан мем.');
				return;
			}

			$memes = $db->executeQuery(new MongoDB\Driver\Query(['_id' => $db->getDocumentID()], ['projection' => ['_id' => 0, "fun.memes" => 1]]))->getValue("0.fun.memes", []);
			$memes = Database\CursorValueExtractor::objectToArray($memes);
			$meme_names = array_keys($memes);
			$meme_values = array_values($memes);

			if (!is_null($meme_values[$meme_index])) {
				$added_time = gmdate("d.m.Y", $meme_values[$meme_index]["date"] + 10800);
				$msg = "%__APPEAL__%, информация о меме:\n✏Имя: {$meme_names[$meme_index]}\n🤵Владелец: %__OWNERNAME__%\n📅Добавлен: {$added_time}\n📂Содержимое: ⬇️⬇️⬇️";
				$request_array = array('peer_id' => $data->object->peer_id, 'message' => $msg, 'attachment' => $meme_values[$meme_index]["content"], 'disable_mentions' => true, 'conversation_message_id' => $data->object->conversation_message_id);

				$meme_values_count = count($meme_values);
				if ($meme_values_count) {
					$previous_element = $meme_index - 1;
					$next_element = $meme_index + 1;
					if ($previous_element <= 0)
						$previous_element = $meme_values_count - 1;
					if ($next_element >= $meme_values_count)
						$next_element = 0;
					$previous_element_str = bot_int_to_emoji_str($previous_element + 1);
					$next_element_str = bot_int_to_emoji_str($next_element + 1);
					$request_array['keyboard'] = vk_keyboard_inline(array(
						array(
							vk_callback_button("{$previous_element_str}⬅", array("fun_memes", $testing_user_id, 1, $previous_element), "secondary"),
							vk_callback_button("➡{$next_element_str}", array("fun_memes", $testing_user_id, 1, $next_element), "secondary"),
						),
						array(vk_callback_button("Закрыть", array('bot_menu', $testing_user_id, 0), "negative"))
					));
				}

				$request = json_encode($request_array, JSON_UNESCAPED_UNICODE);
				$request = vk_parse_vars($request, array("__OWNERNAME__", "__APPEAL__"));
				vk_execute($messagesModule->buildVKSciptAppealByID($data->object->user_id, '__APPEAL__') . "var owner = API.users.get({'user_ids':[{$meme_values[$meme_index]["owner_id"]}]})[0];var __OWNERNAME__ = '@id{$meme_values[$meme_index]["owner_id"]} ('+owner.first_name+' '+owner.last_name+')';return API.messages.edit({$request});");
			} else {
				bot_show_snackbar($data->object->event_id, $data->object->user_id, $data->object->peer_id, '⛔ Внутренняя ошибка: Мем не найден.');
			}
			break;

		default:
			bot_show_snackbar($data->object->event_id, $data->object->user_id, $data->object->peer_id, '⛔ Внутренняя ошибка: Неизвестная команда.');
			break;
	}
}

function fun_memes_handler($data, $db, $finput)
{
	$chatModes = $finput->event->getChatModes();
	if (!$chatModes->getModeValue("allow_memes"))
		return false;

	$meme_name = mb_strtolower($data->object->text);
	if (mb_substr_count($meme_name, '$') > 0)
		return false;
	$query = new MongoDB\Driver\Query(['_id' => $db->getDocumentID()], ['projection' => ["fun.memes.{$meme_name}.content" => 1]]);
	$extractor = $db->executeQuery($query);
	$meme = $extractor->getValue([0, "fun", "memes", $meme_name, "content"], false);
	if ($meme !== false) {
		$botModule = new BotModule($db);
		$request = json_encode(array('peer_id' => $data->object->peer_id, 'message' => "%appeal%,", 'attachment' => $meme, 'disable_mentions' => true), JSON_UNESCAPED_UNICODE);
		$request = vk_parse_var($request, "appeal");
		vk_execute($botModule->buildVKSciptAppealByID($data->object->from_id) . "return API.messages.send({$request});");
		return true;
	}
	return false;
}

function fun_handler($finput)
{
	// Инициализация базовых переменных
	$data = $finput->data;
	$db = $finput->db;

	$text = mb_strtolower($data->object->text);

	if (fun_memes_handler($data, $db, $finput))
		return true;
	return false;
}

function fun_rndwall($finput)
{
	// Инициализация базовых переменных
	$data = $finput->data;
	$argv = $finput->argv;
	$db = $finput->db;

	$messagesModule = new Bot\Messages($db);
	$messagesModule->setAppealID($data->object->from_id);

	$owner_id = intval(bot_get_array_value($argv, 1, 0));

	if($owner_id == 0){
		$messagesModule->sendSilentMessage($data->object->peer_id, "%appeal%, Используйте: !rndwall <id>");
		return;
	}

	$random_number = mt_rand(0, 65535);
	$album_id = "wall";
	$photo = json_decode(vk_userexecute("var random_number={$random_number};var owner_id={$owner_id};var album_id=\"{$album_id}\";var a=API.photos.get({'owner_id':owner_id,'album_id':album_id,'count':0});var photos_count=a.count;var photos_offset=(random_number%photos_count);var photo=API.photos.get({'owner_id':owner_id,'album_id':album_id,'count':1,'offset':photos_offset});return photo;"));
	vk_execute($messagesModule->buildVKSciptAppealByID($data->object->from_id) . "return API.messages.send({'peer_id':{$data->object->peer_id},'attachment':'photo{$photo->response->items[0]->owner_id}_{$photo->response->items[0]->id}'});");
}

function fun_like_avatar($data, $db)
{
	$botModule = new BotModule($db);
	$response = json_decode(vk_userexecute("var user=API.users.get()[0];var user=API.users.get({'user_ids':[{$data->object->from_id}],'fields':'photo_id'})[0];var owner_id='{$data->object->from_id}';var id=user.photo_id.substr(owner_id.length+1, user.photo_id.length);if(API.likes.isLiked({'user_id':user.id,'type':'photo','owner_id':owner_id,'item_id':id}).liked==0){var like=API.likes.add({'type':'photo','owner_id':owner_id,'item_id':id});return {'result':1,'likes':like.likes};}else{return {'result':0};}"))->response;
	if ($response->result == 1)
		$botModule->sendSilentMessage($data->object->peer_id, ", Теперь у тебя {$response->likes} ❤.", $data->object->from_id);
	else
		$botModule->sendSilentMessage($data->object->peer_id, ", Лайк уже стоит.", $data->object->from_id);
}

function fun_choose($finput)
{
	// Инициализация базовых переменных
	$data = $finput->data;
	$argv = $finput->argv;
	$db = $finput->db;

	$botModule = new BotModule($db);
	$options = array();
	$new_str = "";
	for ($i = 1; $i <= sizeof($argv); $i++) {
		$isContinue = true;
		if ($i == sizeof($argv) || mb_strtolower($argv[$i]) == "или") {
			$options[] = $new_str;
			$new_str = "";
			$isContinue = false;
		}
		if ($isContinue) {
			if ($new_str == "") {
				$new_str = $argv[$i];
			} else {
				$new_str = $new_str . " " . $argv[$i];
			}
		}
	}

	if (sizeof($options) < 2) {
		$msg = ", что-то мало вариантов.🤔 Я так не могу.😡";
		vk_execute($botModule->buildVKSciptAppealByID($data->object->from_id) . "return API.messages.send({'peer_id':{$data->object->peer_id},'message':appeal+'{$msg}','disable_mentions':true});");
		return;
	}

	$random_number = mt_rand(0, 65535) % sizeof($options);
	$print_text = $options[$random_number];
	$msg = ", 🤔я выбираю: " . $print_text;
	vk_execute($botModule->buildVKSciptAppealByID($data->object->from_id) . "return API.messages.send({'peer_id':{$data->object->peer_id},'message':appeal+'{$msg}','disable_mentions':true});");
}

function fun_howmuch($finput)
{
	// Инициализация базовых переменных
	$data = $finput->data;
	$argv = $finput->argv;
	$db = $finput->db;

	$messagesModule = new Bot\Messages($db);
	$messagesModule->setAppealID($data->object->from_id);
	$rnd = mt_rand(0, 100);

	if (array_key_exists(1, $argv))
		$unitname = $argv[1];
	else
		$unitname = "";
	$add = bot_get_text_by_argv($argv, 2);

	if ($unitname == "" || $add == "") {
		$messagesModule->sendSilentMessageWithListFromArray($data->object->peer_id, "%appeal%, используйте:", array("Сколько <ед. измерения> <дополнение>"));
		return;
	}

	$add = mb_eregi_replace("\.", "", $add); // Избавляемся от точек.

	$add = mb_strtoupper(mb_substr($add, 0, 1)) . mb_strtolower(mb_substr($add, 1)); // Делает первую букву верхнего регистра, а остальные - нижнего

	$messagesModule->sendSilentMessage($data->object->peer_id, "%appeal%, {$add} {$rnd} {$unitname}.");
}

function fun_bottle($finput)
{
	// Инициализация базовых переменных
	$data = $finput->data;
	$argv = $finput->argv;
	$db = $finput->db;

	$botModule = new BotModule($db);
	if (array_key_exists(1, $argv))
		$command = mb_strtolower($argv[1]);
	else
		$command = "";
	if ($command == "сесть") {
		$random_number = mt_rand(0, 65535);
		vk_execute("
		var members = API.messages.getConversationMembers({'peer_id':{$data->object->peer_id},'fields':'first_name_gen,last_name_gen,sex'});
		var members_count = members.profiles.length;
		var rand_index = {$random_number} % members_count;

		var msg = 'Упс! @id'+members.profiles[rand_index].id+' ('+members.profiles[rand_index].first_name+' '+members.profiles[rand_index].last_name+') сел на бутылку.🍾';

		if(members.profiles[rand_index].sex == 1){
			msg = 'Упс! @id'+members.profiles[rand_index].id+' ('+members.profiles[rand_index].first_name+' '+members.profiles[rand_index].last_name+') села на бутылку.🍾';
		}

		return API.messages.send({'peer_id':{$data->object->peer_id},'message':msg});");
	} elseif ($command == "пара") {
		$random_number1 = mt_rand(0, 65535);
		$random_number2 = mt_rand(0, 65535);
		vk_execute("
		var members = API.messages.getConversationMembers({'peer_id':{$data->object->peer_id},'fields':'first_name_gen,last_name_gen,sex'});
		var members_count = members.profiles.length;
		var rand_index1 = {$random_number1} % members_count;
		var rand_index2 = {$random_number2} % members_count;

		var rand_user1 = members.profiles[rand_index1];
		var rand_user2 = members.profiles[rand_index2];

		var msg = '@id'+rand_user1.id+' ('+rand_user1.first_name+' '+rand_user1.last_name+') и @id'+rand_user2.id+' ('+rand_user2.first_name+' '+rand_user2.last_name+') - прекрасная пара.😍';

		return API.messages.send({'peer_id':{$data->object->peer_id},'message':msg});");
	} else {
		$botModule->sendCommandListFromArray($data, ", используйте:", array(
			'Бутылочка сесть - Садит на бутылку случайного человека',
			'Бутылочка пара - Выводит идеальную пару беседы'
		));
	}
}

function fun_whois_nom($finput)
{
	// Инициализация базовых переменных
	$data = $finput->data;
	$argv = $finput->argv;
	$db = $finput->db;

	$botModule = new BotModule($db);

	$text = bot_get_text_by_argv($argv, 1);
	if ($text == "") {
		$botModule->sendCommandListFromArray($data, ", используйте:", array(
			'!Кто <текст>'
		));
		return;
	}
	$text = mb_eregi_replace("\n", " ", $text); // Убираем символ новой строки

	$random_number = mt_rand(0, 65535);

	vk_execute($botModule->buildVKSciptAppealByID($data->object->from_id) . "var peer_id={$data->object->peer_id};var from_id={$data->object->from_id};var random_number={$random_number};var members=API.messages.getConversationMembers({'peer_id':peer_id});var member=members.profiles[random_number % members.profiles.length];var msg=appeal+', 🤔Я думаю это @id'+ member.id + ' ('+member.first_name+' '+member.last_name+') - {$text}.';API.messages.send({'peer_id':peer_id,'message':msg});");
}

function fun_whois_acc($finput)
{
	// Инициализация базовых переменных
	$data = $finput->data;
	$argv = $finput->argv;
	$db = $finput->db;

	$botModule = new BotModule($db);

	$text = bot_get_text_by_argv($argv, 1);
	if ($text == "") {
		$botModule->sendCommandListFromArray($data, ", используйте:", array(
			'!Кого <текст>'
		));
		return;
	}
	$text = mb_eregi_replace("\n", " ", $text); // Убираем символ новой строки

	$random_number = mt_rand(0, 65535);

	vk_execute($botModule->buildVKSciptAppealByID($data->object->from_id) . "var peer_id={$data->object->peer_id};var from_id={$data->object->from_id};var random_number={$random_number};var members=API.messages.getConversationMembers({'peer_id':peer_id,'fields':'first_name_acc,last_name_acc'});var member=members.profiles[random_number % members.profiles.length];var msg=appeal+', 🤔Я думаю это @id'+ member.id + ' ('+member.first_name_acc+' '+member.last_name_acc+') - {$text}.';API.messages.send({'peer_id':peer_id,'message':msg});");
}

function fun_whois_dat($finput)
{
	// Инициализация базовых переменных
	$data = $finput->data;
	$argv = $finput->argv;
	$db = $finput->db;

	$botModule = new BotModule($db);

	$text = bot_get_text_by_argv($argv, 1);
	if ($text == "") {
		$botModule->sendCommandListFromArray($data, ", используйте:", array(
			'!Кому <текст>'
		));
		return;
	}
	$text = mb_eregi_replace("\n", " ", $text); // Убираем символ новой строки

	$random_number = mt_rand(0, 65535);

	vk_execute($botModule->buildVKSciptAppealByID($data->object->from_id) . "var peer_id={$data->object->peer_id};var from_id={$data->object->from_id};var random_number={$random_number};var members=API.messages.getConversationMembers({'peer_id':peer_id,'fields':'first_name_dat,last_name_dat'});var member=members.profiles[random_number % members.profiles.length];var msg=appeal+', 🤔Я думаю это @id'+ member.id + ' ('+member.first_name_dat+' '+member.last_name_dat+') - {$text}.';API.messages.send({'peer_id':peer_id,'message':msg});");
}

function fun_tts($finput)
{
	// Инициализация базовых переменных
	$data = $finput->data;
	$argv = $finput->argv;
	$db = $finput->db;

	$message = bot_get_text_by_argv($argv, 1);
	$botModule = new BotModule($db);

	if ($message == "") {
		$botModule->sendSilentMessage($data->object->peer_id, ", ⛔используйте \"!tts <текст>\".", $data->object->from_id);
		return;
	}

	$query = array(
		'key' => bot_getconfig("VOICERSS_KEY"),
		'hl' => 'ru-ru',
		'f' => '48khz_16bit_stereo',
		'src' => $message,
		'c' => 'OGG'
	);
	$options = array(
		'http' => array(
			'method'  => 'GET',
			'header'  => 'Content-type: application/x-www-form-urlencoded',
			'content' => http_build_query($query)
		)
	);
	$path = BOTPATH_TMP . "/audio" . mt_rand(0, 65535) . ".ogg";
	file_put_contents($path, file_get_contents('http://api.voicerss.org/?', false, stream_context_create($options)));
	$server = json_decode(vk_execute("return API.docs.getMessagesUploadServer({'peer_id':{$data->object->peer_id},'type':'audio_message'});"))->response->upload_url;
	$audio = json_decode(vk_uploadDocs(array('file' => new CURLFile($path)), $server));
	unlink($path);

	vk_execute($botModule->buildVKSciptAppealByID($data->object->from_id) . "
		var audio = API.docs.save({'file':'{$audio->file}'})[0];
		API.messages.send({'peer_id':{$data->object->peer_id},'message':appeal+',','attachment':'doc'+audio.owner_id+'_'+audio.id,'disable_mentions':true});
		");
}

function fun_shrug($finput)
{
	// Инициализация базовых переменных
	$data = $finput->data;
	$argv = $finput->argv;
	$db = $finput->db;

	$botModule = new BotModule();
	$botModule->sendSilentMessage($data->object->peer_id, "¯\_(ツ)_/¯");
}

function fun_tableflip($finput)
{
	// Инициализация базовых переменных
	$data = $finput->data;
	$argv = $finput->argv;
	$db = $finput->db;

	$botModule = new BotModule();
	$botModule->sendSilentMessage($data->object->peer_id, "(╯°□°）╯︵ ┻━┻");
}

function fun_unflip($finput)
{
	// Инициализация базовых переменных
	$data = $finput->data;
	$argv = $finput->argv;
	$db = $finput->db;

	$botModule = new BotModule();
	$botModule->sendSilentMessage($data->object->peer_id, "┬─┬ ノ( ゜-゜ノ)");
}

function fun_info($finput)
{
	// Инициализация базовых переменных
	$data = $finput->data;
	$argv = $finput->argv;
	$db = $finput->db;

	$botModule = new BotModule($db);

	$expression = bot_get_text_by_argv($argv, 1);

	if ($expression == "") {
		$botModule->sendSilentMessage($data->object->peer_id, ", ⛔используйте \"Инфа <выражение>\".", $data->object->from_id);
		return;
	}

	$rnd = mt_rand(0, 100);

	$botModule->sendSilentMessage($data->object->peer_id, ", 📐Инфа, что {$expression} — {$rnd}%.", $data->object->from_id);
}

function fun_say($finput)
{
	// Инициализация базовых переменных
	$data = $finput->data;
	$argv = $finput->argv;
	$db = $finput->db;

	$botModule = new BotModule($db);

	$params = bot_get_text_by_argv($argv, 1);

	parse_str($params, $vars);

	$appeal_id = null;

	if (!array_key_exists("msg", $vars)) {
		$botModule->sendSilentMessage($data->object->peer_id, ", ⛔Param <msg> not found!", $data->object->from_id);
		return;
	}

	if (array_key_exists("appeal_id", $vars))
		$appeal_id = $vars["appeal_id"];

	$botModule->sendSilentMessage($data->object->peer_id, $vars["msg"], $appeal_id);
}

function fun_marriage($finput)
{
	// Инициализация базовых переменных
	$data = $finput->data;
	$argv = $finput->argv;
	$db = $finput->db;

	$botModule = new BotModule($db);

	$marriages_db = $db->executeQuery(new MongoDB\Driver\Query(['_id' => $db->getDocumentID()], ['projection' => ['_id' => 0, 'fun.marriages' => 1]]))->getValue("0.fun.marriages", [
		'user_info' => [],
		'list_count' => 0,
		'list' => []
	]);
	$marriages_db = Database\CursorValueExtractor::objectToArray($marriages_db);

	$member_id = 0;

	$member = bot_get_array_value($argv, 1, "");
	if (array_key_exists(0, $data->object->fwd_messages))
		$member_id = $data->object->fwd_messages[0]->from_id;
	elseif (bot_get_userid_by_mention($member, $member_id)) {
	} elseif (bot_get_userid_by_nick($db, $member, $member_id)) {
	} elseif (is_numeric($member))
		$member_id = intval($member);
	else {
		if (array_key_exists(1, $argv))
			$word1 = mb_strtolower($argv[1]);
		else
			$word1 = "";

		switch ($word1) {
			case 'да':
				if (array_key_exists("id{$data->object->from_id}", $marriages_db["user_info"]) && $marriages_db["user_info"]["id{$data->object->from_id}"]["type"] == 0) {
					$partner_id = $marriages_db["user_info"]["id{$data->object->from_id}"]["partner_id"];
					if (array_key_exists("id{$partner_id}", $marriages_db["user_info"])) {
						$botModule->sendSilentMessage($data->object->peer_id, ", ⛔@id{$partner_id} (Пользователь) уже находится в браке.", $data->object->from_id);
						unset($marriages_db["user_info"]["id{$data->object->from_id}"]);
						return;
					}
					$marriages_db["list"][] = array(
						'partner_1' => $partner_id,
						'partner_2' => $data->object->from_id,
						'start_time' => time(),
						'end_time' => 0,
						'terminated' => false
					);
					$marriage_id = $marriages_db["list_count"]; // Получение ID брака
					$marriages_db["list_count"]++;
					$marriages_db["user_info"]["id{$partner_id}"] = array(
						'type' => 1,
						'marriage_id' => $marriage_id
					);
					$marriages_db["user_info"]["id{$data->object->from_id}"] = array(
						'type' => 1,
						'marriage_id' => $marriage_id
					);
					vk_execute("
						var users_info = API.users.get({'user_ids':[{$partner_id},{$data->object->from_id}]});
						var partner_1 = users_info[0];
						var partner_2 = users_info[1];
						var msg = '❤@id'+partner_1.id+' ('+partner_1.first_name+' '+partner_1.last_name+') и @id'+partner_2.id+' ('+partner_2.first_name+' '+partner_2.last_name+') теперь семья❤';
						API.messages.send({'peer_id':{$data->object->peer_id},'message':msg});
						");
				} else {
					$botModule->sendSilentMessage($data->object->peer_id, ", ⛔У вас нет приглашения о заключении брака.", $data->object->from_id);
				}
				break;

			case 'нет':
				if (array_key_exists("id{$data->object->from_id}", $marriages_db["user_info"]) && $marriages_db["user_info"]["id{$data->object->from_id}"]["type"] == 0) {
					$partner_id = $marriages_db["user_info"]["id{$data->object->from_id}"]["partner_id"];
					unset($marriages_db["user_info"]["id{$data->object->from_id}"]);
					vk_execute("
						var users_info = API.users.get({'user_ids':[{$partner_id},{$data->object->from_id}],'fields':'sex,first_name_ins,last_name_ins'});
						var partner_1 = users_info[0];
						var partner_2 = users_info[1];
						var sex_word = 'захотела';
						if(partner_1.sex == 1){ sex_word = 'захотел'; }
						var msg = '@id'+partner_2.id+' ('+partner_2.first_name+' '+partner_2.last_name+') не '+sex_word+' вступать в брак с @id'+partner_1.id+' ('+partner_1.first_name_ins+' '+partner_1.last_name_ins+').';
						API.messages.send({'peer_id':{$data->object->peer_id},'message':msg});
						");
				} else {
					$botModule->sendSilentMessage($data->object->peer_id, ", ⛔У вас нет приглашения о заключении брака.", $data->object->from_id);
				}
				break;

			case 'развод':
				if (array_key_exists("id{$data->object->from_id}", $marriages_db["user_info"]) && $marriages_db["user_info"]["id{$data->object->from_id}"]["type"] == 1) {
					$marriage_info = &$marriages_db["list"][$marriages_db["user_info"]["id{$data->object->from_id}"]["marriage_id"]];
					$marriage_info["terminated"] = true;
					$marriage_info["end_time"] = time();
					unset($marriages_db["user_info"]["id{$marriage_info["partner_1"]}"]);
					unset($marriages_db["user_info"]["id{$marriage_info["partner_2"]}"]);
					vk_execute("
						var users_info = API.users.get({'user_ids':[{$marriage_info["partner_1"]},{$marriage_info["partner_2"]}]});
						var partner_1 = users_info[0];
						var partner_2 = users_info[1];
						var msg = '💔@id'+partner_1.id+' ('+partner_1.first_name+' '+partner_1.last_name+') и @id'+partner_2.id+' ('+partner_2.first_name+' '+partner_2.last_name+') больше не семья💔';
						API.messages.send({'peer_id':{$data->object->peer_id},'message':msg});
						");
				} else {
					$botModule->sendSilentMessage($data->object->peer_id, ", ⛔Вы не состоите в браке.", $data->object->from_id);
				}
				break;

			case 'помощь':
				$botModule->sendCommandListFromArray($data, ", используйте:", array(
					'Брак - Информация о текущем браке',
					'Брак <пользователь> - Отправление запроса о заключении в брака',
					'Брак да - Одобрение запроса',
					'Брак нет - Отклонение запроса',
					'Брак развод - Развод текущего брака',
					'Брак помощь - Помощь в системе браков'
				));
				break;

			default:
				if (array_key_exists("id{$data->object->from_id}", $marriages_db["user_info"]) && $marriages_db["user_info"]["id{$data->object->from_id}"]["type"] == 1) {
					$marriage_info = $marriages_db["list"][$marriages_db["user_info"]["id{$data->object->from_id}"]["marriage_id"]];
					vk_execute("
						var users_info = API.users.get({'user_ids':[{$marriage_info["partner_1"]},{$marriage_info["partner_2"]}],'fields':'first_name_ins,last_name_ins'});
						var partner_1 = users_info[0];
						var partner_2 = users_info[1];
						var msg = '❤@id'+partner_1.id+' ('+partner_1.first_name+' '+partner_1.last_name+') находится в счастливом браке с @id'+partner_2.id+' ('+partner_2.first_name_ins+' '+partner_2.last_name_ins+')❤';
						API.messages.send({'peer_id':{$data->object->peer_id},'message':msg,'disable_mentions':true});
						");
				} else {
					$botModule->sendSilentMessage($data->object->peer_id, ", ⛔Вы не состоите в браке.", $data->object->from_id);
				}
				break;
		}
		$bulk = new MongoDB\Driver\BulkWrite;
		$bulk->update(['_id' => $db->getDocumentID()], ['$set' => ["fun.marriages" => $marriages_db]]);
		$db->executeBulkWrite($bulk);
		return;
	}


	if (!array_key_exists("id{$member_id}", $marriages_db["user_info"])) {
		if (array_key_exists("id{$data->object->from_id}", $marriages_db["user_info"])) {
			$botModule->sendSilentMessage($data->object->peer_id, ", ⛔Вы уже состоите в браке или получили приглашение.", $data->object->from_id);
			return;
		}
		$res = json_decode(vk_execute($botModule->buildVKSciptAppealByID($data->object->from_id) . "
			var member = API.users.get({'user_ids':[{$member_id}],'fields':'first_name_dat,last_name_dat'})[0];
			var members = API.messages.getConversationMembers({'peer_id':{$data->object->peer_id}});
			var member_id = {$member_id};
			if(member_id == {$data->object->from_id}){
				API.messages.send({'peer_id':{$data->object->peer_id},'message':appeal+', ⛔Нельзя зкалючить брак с самим собой.','disable_mentions':true});
				return false;
			}

			var isContinue = false;
			var i = 0; while(i < members.profiles.length){
				if(members.profiles[i].id == member_id){
					isContinue = true;
					i = members.profiles.length;
				}
				i = i + 1;
			}
			if(!isContinue){
				API.messages.send({'peer_id':{$data->object->peer_id},'message':appeal+', ❗Указанного человека нет в беседе!','disable_mentions':true});
				return false;
			}
			else{
				API.messages.send({'peer_id':{$data->object->peer_id},'message':appeal+', ✅Приглашение о заключении брака отправлено @id{$member_id} ('+member.first_name_dat.substr(0, 2)+'. '+member.last_name_dat+').'});
				return true;
			}
			"))->response;
		if ($res) {
			$marriages_db["user_info"]["id{$member_id}"] = array(
				'type' => 0,
				'partner_id' => $data->object->from_id
			);
			$bulk = new MongoDB\Driver\BulkWrite;
			$bulk->update(['_id' => $db->getDocumentID()], ['$set' => ["fun.marriages" => $marriages_db]]);
			$db->executeBulkWrite($bulk);
		}
	} else {
		$botModule->sendSilentMessage($data->object->peer_id, ", ⛔@id{$member_id} (Пользователь) уже состоит в браке или получил приглашение.", $data->object->from_id);
	}
}

function fun_show_marriage_list($finput)
{
	// Инициализация базовых переменных
	$data = $finput->data;
	$argv = $finput->argv;
	$db = $finput->db;

	$marriages_db = $db->executeQuery(new MongoDB\Driver\Query(['_id' => $db->getDocumentID()], ['projection' => ['_id' => 0, 'fun.marriages' => 1]]))->getValue("0.fun.marriages", [
		'user_info' => [],
		'list_count' => 0,
		'list' => []
	]);
	$marriages_db = Database\CursorValueExtractor::objectToArray($marriages_db);

	$botModule = new BotModule($db);

	$date = time(); // Переменная времени

	if (array_key_exists(1, $argv) && !is_numeric($argv[1]))
		$word = mb_strtolower($argv[1]);
	else
		$word = "";


	if ($word == "история") {
		$list = $marriages_db["list"];

		if (count($list) == 0) {
			$botModule->sendSilentMessage($data->object->peer_id, ", в беседе нет браков!", $data->object->from_id);
			return;
		}

		if (array_key_exists(2, $argv) && is_numeric($argv[2]))
			$list_number_from_word = intval($argv[2]);
		else
			$list_number_from_word = 1;

		/////////////////////////////////////////////////////
		////////////////////////////////////////////////////
		$list_in = &$list; // Входной список
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
			if ($list_out[$i]["terminated"]) {
				$days = (($list_out[$i]["end_time"] - $list_out[$i]["start_time"]) - ($list_out[$i]["end_time"] - $list_out[$i]["start_time"]) % 86400) / 86400;
				$str_info = gmdate("d.m.Y", $list_out[$i]["start_time"] + 10800) . " - " . gmdate("d.m.Y | {$days} д.", $list_out[$i]["end_time"] + 10800);
				$list_out[$i]["str_info"] = $str_info;
				unset($list_out[$i]["start_time"]);
				unset($list_out[$i]["end_time"]);
				unset($list_out[$i]["terminated"]);
			} else {
				$days = (($date - $list_out[$i]["start_time"]) - ($date - $list_out[$i]["start_time"]) % 86400) / 86400;
				$str_info = gmdate("с d.m.Y | {$days} д.", $list_out[$i]["start_time"] + 10800);
				$list_out[$i]["str_info"] = $str_info;
				unset($list_out[$i]["start_time"]);
				unset($list_out[$i]["end_time"]);
				unset($list_out[$i]["terminated"]);
			}
		}

		$marriages_json = json_encode($list_out, JSON_UNESCAPED_UNICODE);

		vk_execute($botModule->buildVKSciptAppealByID($data->object->from_id) . "var marriages={$marriages_json};var current_date={$date};var partner_1_info=API.users.get({'user_ids':marriages@.partner_1});var partner_2_info=API.users.get({'user_ids':marriages@.partner_2});var msg=appeal+', история браков беседы [$list_number/{$list_max_number}]:';var i=0;while(i<marriages.length){var partner_1; var partner_2;var j=0;while(j<partner_1_info.length){if(partner_1_info[j].id==marriages[i].partner_1){partner_1=partner_1_info[j];j=partner_1_info.length;}j=j+1;}var j=0;while(j<partner_2_info.length){if(partner_2_info[j].id==marriages[i].partner_2){partner_2=partner_2_info[j];j=partner_2_info.length;}j=j+1;}msg = msg+'\\n✅@id'+marriages[i].partner_1+' ('+partner_1.first_name+') и @id'+marriages[i].partner_2+' ('+partner_2.first_name+') ('+marriages[i].str_info+')';i=i+1;}API.messages.send({'peer_id':{$data->object->peer_id},'message':msg,'disable_mentions':true});");
	} elseif ($word == "") {
		$list = array();
		for ($i = 0; $i < count($marriages_db["list"]); $i++) {
			if (!$marriages_db["list"][$i]["terminated"]) {
				$list[] = $marriages_db["list"][$i];
			}
		}

		if (count($list) == 0) {
			$botModule->sendSilentMessage($data->object->peer_id, ", в беседе нет браков!", $data->object->from_id);
			return;
		}

		if (array_key_exists(1, $argv) && is_numeric($argv[1]))
			$list_number_from_word = intval($argv[1]);
		else
			$list_number_from_word = 1;

		/////////////////////////////////////////////////////
		////////////////////////////////////////////////////
		$list_in = &$list; // Входной список
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

		$marriages_json = json_encode($list_out, JSON_UNESCAPED_UNICODE);

		vk_execute($botModule->buildVKSciptAppealByID($data->object->from_id) . "var marriages={$marriages_json};var current_date={$date};var partner_1_info=API.users.get({'user_ids':marriages@.partner_1});var partner_2_info=API.users.get({'user_ids':marriages@.partner_2});var msg=appeal+', 🤵👰браки в беседе [$list_number/{$list_max_number}]:';var i=0;while(i<marriages.length){var days=((current_date-marriages[i].start_time)-(current_date-marriages[i].start_time)%86400)/86400;msg=msg+'\\n❤@id'+marriages[i].partner_1+' ('+partner_1_info[i].first_name+') и @id'+marriages[i].partner_2+' ('+partner_2_info[i].first_name+')❤ ('+days+' д.)';i=i+1;}API.messages.send({'peer_id':{$data->object->peer_id},'message':msg,'disable_mentions':true});");
	} else {
		$botModule->sendCommandListFromArray($data, ", используйте:", array(
			'Браки <список> - Браки в беседе',
			'Браки история <список> - Полная история браков беседы'
		));
	}
}
