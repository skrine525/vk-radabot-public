<?php

class VKVariable
{
	private $tmpvar_name;															// Название временной переменной
	private $tmpvar_array;															// Массив временной переменно
	private $var_code;																// VK Script код переменных

	function __construct()
	{
		$this->tmpvar_name = self::generateRandomName(3);
		$this->tmpvar_array = [];
		$this->var_code = "";
	}

	private static function generateRandomName($length)
	{
		$characters = 'abcdefghijklmnopqrstuvwxyz';
		$charactersLength = strlen($characters);
		$randomString = '';
		for ($i = 0; $i < $length; $i++) {
			$randomString .= $characters[mt_rand(0, $charactersLength - 1)];
		}
		return $randomString;
	}

	public function getCode()
	{
		if(count($this->tmpvar_array) > 0)
			$tmpvar_code = "var {$this->tmpvar_name}=" . json_encode($this->tmpvar_array, JSON_UNESCAPED_UNICODE) . ";";
		else
			$tmpvar_code = '';
		return "{$tmpvar_code}{$this->var_code}";
	}

	public function var(string $name, $value, bool $parse_array = false)
	{
		switch (gettype($value)) {
			case 'boolean':
				$new_val = boolval($value) ? 'true' : 'false';
				$this->var_code .= "{$name}={$new_val};";
				break;

			case 'integer':
				$new_val = intval($value);
				$this->var_code .= "{$name}={$new_val};";
				break;

			case 'double':
				$new_val = floatval($value);
				$this->var_code .= "{$name}={$new_val};";
				break;

			case 'string':
				$tmpvar_index = count($this->tmpvar_array);
				$this->tmpvar_array[] = $value;
				$this->var_code .= "{$name}={$this->tmpvar_name}[{$tmpvar_index}];";
				break;

			case 'array':
				if ($parse_array) {
					$last_type = 0;
					$can_plus = false;
					$object_code = '';
					foreach ($value as $val) {
						if ($last_type === 0)
							$last_type = (gettype($val) == 'string') ? $val : 0;
						else {
							$plus_symbol = '';
							if ($can_plus)
								$plus_symbol = '+';
							else
								$can_plus = true;
							switch ($last_type) {
								case 'int':
									$new_val = intval($val);
									$object_code .= "{$plus_symbol}{$new_val}";
									break;

								case 'float':
									$new_val = floatval($val);
									$object_code .= "{$plus_symbol}{$new_val}";
									break;

								case 'str':
									$tmpvar_index = count($this->tmpvar_array);
									$this->tmpvar_array[] = strval($val);
									$object_code .= "{$plus_symbol}{$this->tmpvar_name}[{$tmpvar_index}]";
									break;

								case 'bool':
									$new_val = boolval($val) ? 'true' : 'false';
									$object_code .= "{$plus_symbol}{$new_val}";
									break;

								case 'var':
									$object_code .= "{$plus_symbol}{$val}";
									break;
							}
							$last_type = 0;
						}
					}
					if($object_code != '')
						$this->var_code .= "{$name}={$object_code};";
				} else {
					$new_val = json_encode($value, JSON_UNESCAPED_UNICODE);
					$this->var_code .= "{$name}={$new_val};";
				}
				break;
		}
	}
}

// Медоты для работы с VK API

function vk_call($method, $parametres, $version = 5.84)
{
	// Устанавливаем системные параметры
	$parametres['access_token'] = bot_getconfig('VK_GROUP_TOKEN');
	$parametres['v'] = $version;

	$options = [
		'http' => [
			'method'  => 'POST',
			'header'  => 'Content-type: application/x-www-form-urlencoded',
			'content' => http_build_query($parametres)
		]
	];
	return file_get_contents("https://api.vk.com/method/{$method}", false, stream_context_create($options));
}

function vk_execute($code)
{
	return vk_call('execute', ['code' => $code]);
}

function vk_longpoll($data, $ts, $wait = 25)
{
	return file_get_contents("{$data->server}?act=a_check&key={$data->key}&ts={$ts}&wait={$wait}");
}

function vk_userexecute($code, $version = 5.84)
{
	$parametres = [
		'code' => $code,
		'access_token' => bot_getconfig("VK_USER_TOKEN"),
		'v' => $version
	];

	$options = [
		'http' => [
			'method'  => 'POST',
			'header'  => 'Content-type: application/x-www-form-urlencoded',
			'content' => http_build_query($parametres)
		]
	];
	return file_get_contents('https://api.vk.com/method/execute', false, stream_context_create($options));
}

/// Клавиатура

function vk_text_button($label, $payload, $color)
{
	$payload_json = "";
	if (gettype($payload) == "array")
		$payload_json = json_encode($payload, JSON_UNESCAPED_UNICODE);
	return ['action' => ['type' => 'text', 'payload' => $payload_json, 'label' => $label], 'color' => $color];
}

function vk_callback_button($label, $payload, $color)
{
	$payload_json = "";
	if (gettype($payload) == "array")
		$payload_json = json_encode($payload, JSON_UNESCAPED_UNICODE);
	return ['action' => ['type' => 'callback', 'payload' => $payload_json, 'label' => $label], 'color' => $color];
}

function vk_keyboard($one_time, $buttons = [])
{
	$keyboard_json = json_encode(['one_time' => $one_time, 'buttons' => $buttons], JSON_UNESCAPED_UNICODE);
	return $keyboard_json;
}

function vk_keyboard_inline($buttons = [])
{
	$keyboard_json = json_encode(['inline' => true, 'buttons' => $buttons], JSON_UNESCAPED_UNICODE);
	return $keyboard_json;
}

function vk_parse_var($data, $varname)
{
	return mb_ereg_replace("%{$varname}%", "\"+{$varname}+\"", $data); // Если будут проблемы, поменять на mb_eregi_replace
}

function vk_parse_vars($data, $varnames)
{
	if (gettype($varnames) != "array")
		return $data;
	for ($i = 0; $i < count($varnames); $i++) {
		$data = vk_parse_var($data, $varnames[$i]);
	}
	return $data;
}

/// Загрузка медии на сервер

function vk_uploadDocs($aPost, $url)
{
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_SAFE_UPLOAD, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $aPost);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	$res = curl_exec($ch);
	curl_close($ch);
	return $res;
}
