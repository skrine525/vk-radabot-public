# Python модули
import time, requests, json, threading, traceback

# Части бота
from radabot.bot.fun import FunSeamCarving
from radabot.core.manager import ChatModes, UserPermissions
from radabot.core.vk import VK_API, longpoll
from radabot.core.system import SYSTEM_PATHS, Config, ManagerData, PHPCommandIntegration, initdir, write_log
from radabot.bot.handler import handle_event

# Инициализация разных компонентов
Config.read_file()										# Считываем файл config.json
ManagerData.read_file()									# Считываем файл manager.json
UserPermissions.init_default_states()					# Инициализируем стандартные состояния UserPermissions
ChatModes.init_default_states()							# Инициализируем стандартные состояния Chat
PHPCommandIntegration.init()							# Инициализация команд php
FunSeamCarving.start_queue_thread()						# Запускаем поток обработки "жмых" изображений
initdir()												# Инициализация системных директорий

# Базоваые константы
WORKERS_MAX_COUNT = 1									# Максимальное количество одновременных обработчиков
LONGPOLL_ERROR_COOLDOWN = 3								# Количество секунд ожидания после ошибки в основном потоке

# Базовые переменные
vk_api = VK_API(Config.get('VK_GROUP_TOKEN'))			# VK API
event_queue = []										# Очередь событий на обработку
active_workers = []										# Массив потоков обработчика

# Функция обработки события из очереди
def event_worker(event):
	try:
		handle_event(vk_api, event)
	except:
		trace = traceback.format_exc()
		write_log(SYSTEM_PATHS.ERROR_LOG_FILE, f"\n{trace[:-1]}\n")

# Поток обработки очереди
def queue_handler():
	while True:
		for worker in active_workers:
			if not worker.is_alive():
				active_workers.remove(worker)

		for event in event_queue:
			if len(active_workers) < WORKERS_MAX_COUNT:
				worker = threading.Thread(target=event_worker, daemon=False, args=[event])
				active_workers.append(worker)
				worker.start()
				event_queue.remove(event)
			else:
				break
		time.sleep(0.05)


# Основной код запуска
if __name__ == "__main__":
	write_log(SYSTEM_PATHS.LONGPOLL_LOG_FILE, "Longpoll started")
	active = False

	lp_server = ''
	lp_key = ''
	lp_ts = 0

	attempts_count = 1
	while True:
		vk_response_dict = vk_api.call('groups.getLongPollServer', {'group_id': Config.get('VK_GROUP_ID')})
		response_data = vk_response_dict.get('response', None)
		if response_data is not None:
			lp_server = response_data["server"]
			lp_key = response_data["key"]
			lp_ts = response_data["ts"]
			active = True
			#del vk_response_dict
			#del response_data
			write_log(SYSTEM_PATHS.LONGPOLL_LOG_FILE, "Longpoll data: Received")
			break
		else:
			write_log(SYSTEM_PATHS.LONGPOLL_LOG_FILE, "Longpoll data: Receiving failed".format(attempts_count))
			attempts_count = attempts_count + 1
		if attempts_count > 5:
			break
		time.sleep(LONGPOLL_ERROR_COOLDOWN)
	del attempts_count

	if active:
		queue_thread = threading.Thread(target=queue_handler, daemon=True)
		queue_thread.start()

	while active:
		try:
			data_dict = longpoll(lp_server, lp_key, lp_ts)
			failed = data_dict.get('failed', None)

			if failed == 1:
				lp_ts = data_dict["ts"]
				write_log(SYSTEM_PATHS.LONGPOLL_LOG_FILE, "Longpoll data update: ts")
			elif failed == 2:
				vk_response_dict = vk_api.call('groups.getLongPollServer', {'group_id': Config.get('VK_GROUP_ID')})
				response_data = vk_response_dict.get('response', None)
				if response_data is not None:
					lp_key = response_data['key']
					write_log(SYSTEM_PATHS.LONGPOLL_LOG_FILE, "Longpoll data update: key")
				else:
					write_log(SYSTEM_PATHS.LONGPOLL_LOG_FILE, "Longpoll data updating failed: key")
					time.sleep(LONGPOLL_ERROR_COOLDOWN)
				#del vk_response_dict
				#del response_data
			elif failed == 3:
				vk_response_dict = vk_api.call('groups.getLongPollServer', {'group_id': Config.get('VK_GROUP_ID')})
				response_data = vk_response_dict.get('response', None)
				if response_data is not None:
					lp_key = response_data["key"]
					lp_ts = response_data["ts"]
					write_log(SYSTEM_PATHS.LONGPOLL_LOG_FILE, "Longpoll data update: ts, key")
				else:
					write_log(SYSTEM_PATHS.LONGPOLL_LOG_FILE, "Longpoll data updating failed: ts, key")
					time.sleep(LONGPOLL_ERROR_COOLDOWN)
				#del vk_response_dict
				#del response_data
			else:
				for event in data_dict["updates"]:
					event_queue.append(event)
				lp_ts = data_dict["ts"]
		except requests.exceptions.ConnectionError:
			write_log(SYSTEM_PATHS.LONGPOLL_LOG_FILE, "Connection Error")
			time.sleep(LONGPOLL_ERROR_COOLDOWN)
		except KeyboardInterrupt:
			write_log(SYSTEM_PATHS.LONGPOLL_LOG_FILE, "Keyboard Interrupt")
			active = False
		except BaseException as e:
			write_log(SYSTEM_PATHS.LONGPOLL_LOG_FILE, f"Unexpected {e=}, {type(e)=}")
			time.sleep(LONGPOLL_ERROR_COOLDOWN)