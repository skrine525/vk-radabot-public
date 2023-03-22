import json, traceback, time, os, copy
from datetime import datetime
from typing import Callable
from enum import Enum

from .manager import ChatModes
from . import bot
from .bot import DEFAULT_MESSAGES, ChatStats
from .system import ArgumentParser, Config, PayloadParser, ValueExtractor, generate_random_string, write_log, ChatDatabase
from .vk import VK_API, KeyboardBuilder, VKVariable
from .system import SYSTEM_PATHS


class ChatEventManager:
    #############################
    #############################
    # Внутренние классы

    # Класс системных команд
    class IntenalCommands:
        # Команда репорта ошибки
        class ErrorReportCommand:
            @staticmethod
            def callback_button_command(callback_object):
                event = callback_object["event"]
                payload = callback_object["payload"]
                db = callback_object["db"]
                output = callback_object["output"]

                aos = AdvancedOutputSystem(output, event, db)

                logname = payload.get_str(1, None)

                if logname is None:
                    aos.show_snackbar(text='⛔ Ошибка названия журнала.')
                    return
                elif not os.path.isfile(os.path.join(SYSTEM_PATHS.EXEC_LOG_DIR, "{}.log".format(logname))):
                    aos.show_snackbar(text='⛔ Указанного журнала не существует.')
                    return

                reports_collection = db.get_collection("error_reports")

                find_result = reports_collection.find_one({"log_name": logname})
                if find_result is None:
                    report = {
                        'log_name': logname,
                        'chat_id': event["object"]["peer_id"] - 2000000000,
                        'user_id': event["object"]["user_id"],
                        'date': datetime.now(),
                        'is_solved': False
                    }
                    reports_collection.insert_one(report)

                    # Генерация скрипта отправки соощбения суперпользователю
                    superuser_message_text = 'Репорт ошибки:\n🆔Чат: {}\n📝Журнал: {}'.format(report["chat_id"], logname)
                    send_params = {'peer_id': Config.get("SUPERUSER_ID"), 'random_id': 0, 'message': superuser_message_text}
                    superuser_message_script = "API.messages.send({});".format(json.dumps(send_params, ensure_ascii=False, separators=(',', ':')))

                    message_text = "✅Репорт отправлен.\n\n" + DEFAULT_MESSAGES.MESSAGE_EXECUTION_ERROR.format(logname=logname)
                    aos.messages_edit(message=VKVariable.Multi('var', 'appeal', 'str', message_text), script=superuser_message_script)
                else:
                    aos.show_snackbar(text='⛔ Репорт уже отправлен.')

    # Класс версий и конвертирования Event объекта (необходим для обратной совместимости)
    class EventObjectConverter:
        # Перечисление версий
        class Version(Enum):
            ORIGIN = 1
            OLD_5_84 = 2

        # Иключение Неправильная версия
        class InvalidVersion(Exception):
            def __init__(self, message: str):
                self.message = message

        # Статический метод конвертации в старые версии
        @staticmethod
        def convert(event: dict, version: int):
            if version == ChatEventManager.EventObjectConverter.Version.OLD_5_84:
                if event["type"] == "message_new":
                    new_event = copy.deepcopy(event)                            # Копируем словарь event в new_event
                    new_event["v"] = 5.84                                       # Изменяем версию на 5.84
                    new_event["object"] = new_event["object"]["message"]        # Копируем object->message в object
                elif event["type"] == "message_event":
                    new_event = copy.deepcopy(event)                            # Копируем словарь event в new_event
                return new_event
            else:
                raise ChatEventManager.EventObjectConverter.InvalidVersion("Invalid version parameter")

    #############################
    #############################
    # Исключения

    # Исключение Базы данных
    class DatabaseException(Exception):
        def __init__(self, message: str):
            self.message = message

    # Исключение Неизвестная команда
    class UnknownCommandException(Exception):
        def __init__(self, message: str, command: str):
            super(ChatEventManager.UnknownCommandException, self).__init__(message)
            self.command = command
            self.message = message

    # Исключние неправильных событий при создании объекта ChatEventManager
    class InvalidEventException(Exception):
        def __init__(self, message: str):
            self.message = message

    #############################
    #############################
    # Конструктор

    def __init__(self, vk_api: VK_API, event: dict):
        if event["type"] == "message_new" or event["type"] == "message_event":
            self.__vk_api = vk_api
            self.__event = event
            self.__message_commands = {}
            self.__text_button_commands = {}
            self.__callback_button_commands = {}
            self.__message_handlers = []

            # Получение peer_id в зависимости от типа события
            peer_id = self.__event["object"]["message"]["peer_id"] if self.__event["type"] == "message_new" else self.__event["object"]["peer_id"]
            self.__db = ChatDatabase(Config.get('DATABASE_HOST'), Config.get('DATABASE_PORT'), Config.get('DATABASE_NAME'), peer_id)

            self.__chat_stats = ChatStats(self.__db)        # Инициализация менеджера ведения статисики
            self.__chat_modes = ChatModes(self.__db)          # Инициализация менеджера режимов беседы

            # Добавление Callback команды для репорта ошибок
            self.add_callback_button_command('report_error', ChatEventManager.IntenalCommands.ErrorReportCommand.callback_button_command)
        else:
            raise ChatEventManager.InvalidEventException('ChatEventManager support only message_new & message_event types')

    #############################
    #############################
    # Методы доступа к private полям

    @property
    def event(self):
        return self.__event
    
    @property
    def chat_stats(self):
        return self.__chat_stats

    @property
    def chat_modes(self):
        return self.__chat_modes

    #############################
    #############################
    # Действия над командами

    # Добавление Message команды
    def add_message_command(self, command: str, callback: Callable, args: list = [], ignore_db: bool = False, event_version=EventObjectConverter.Version.ORIGIN) -> bool:
        command = command.lower()
        if command in self.__message_commands:
            return False
        else:
            self.__message_commands[command] = {
                'callback': callback,
                'args': args,
                'ignore_db': ignore_db,
                'event_version': event_version
            }
            return True

    # Добавление Text Button команды
    def add_text_button_command(self, command: str, callback: Callable, args: list = [], ignore_db: bool = False, event_version=EventObjectConverter.Version.ORIGIN) -> bool:
        command = command.lower()
        if command in self.__text_button_commands:
            return False
        else:
            self.__text_button_commands[command] = {
                'callback': callback,
                'args': args,
                'ignore_db': ignore_db,
                'event_version': event_version
            }
            return True

    # Добавление Callback Button команды
    def add_callback_button_command(self, command: str, callback: Callable, args: list = [], ignore_db: bool = False, event_version=EventObjectConverter.Version.ORIGIN) -> bool:
        command = command.lower()
        if command in self.__callback_button_commands:
            return False
        else:
            self.__callback_button_commands[command] = {
                'callback': callback,
                'args': args,
                'ignore_db': ignore_db,
                'event_version': event_version
            }
            return True

    # Добавление Message обработчика (Если не выполнена Message команда)
    def add_message_handler(self, callback: Callable, ignore_db: bool = False, event_version=EventObjectConverter.Version.ORIGIN) -> bool:
        if callback in self.__message_handlers:
            return False
        else:
            handler = {
                'callback': callback,
                'ignore_db': ignore_db,
                'event_version': event_version
            }
            self.__message_handlers.append(handler)
            return True

    # Проверка существования Message команды
    def is_message_command(self, command: str) -> bool:
        return command in self.__message_commands

    # Проверка существования Text Button команды
    def is_text_button_command(self, command: str) -> bool:
        return command in self.__text_button_commands

    # Проверка существования Callback Button команды
    def is_callback_button_command(self, command: str) -> bool:
        return command in self.__callback_button_commands

    # Запуск обработки Message команды
    def run_message_command(self, event, output):
        args = ArgumentParser(event["object"]["message"]["text"])
        command = args.get_str(0, '').lower()
        if self.is_message_command(command):
            if not self.__message_commands[command]['ignore_db'] and not self.__db.is_exists:
                raise ChatEventManager.DatabaseException('Command \'{}\' requires document in Database'.format(command))

            # Если версия Event'а не совпадает с оригиналом, то конвертируем в нужную версию, для обратной совместимости
            if self.__message_commands[command]["event_version"] != ChatEventManager.EventObjectConverter.Version.ORIGIN:
                event = ChatEventManager.EventObjectConverter.convert(event, self.__message_commands[command]["event_version"])

            # Подготовка объекта вызова
            callback_object = {
                "event": event,
                "args": args,
                "vk_api": self.__vk_api,
                "manager": self,
                "db": self.__db,
                "output": output
            }

            callback = self.__message_commands[command]["callback"]
            callback_args = [callback_object] + self.__message_commands[command]["args"]
            callback(*callback_args)
        else:
            raise ChatEventManager.UnknownCommandException('Command \'{}\' not found'.format(command), command)

    # Запуск обработки Message команды
    def run_text_button_command(self, event, output):
        if 'payload' in event["object"]:
            payload = json.loads(event["object"]["message"]["payload"])
            command = payload.get('command', '')

            if self.is_text_button_command(command):
                if not self.__text_button_commands[command]['ignore_db'] and not self.__db.is_exists:
                    raise ChatEventManager.DatabaseException('Command \'{}\' requires document in Database'.format(command))

                # Если версия Event'а не совпадает с оригиналом, то конвертируем в нужную версию, для обратной совместимости
                if self.__text_button_commands[command]["event_version"] != ChatEventManager.EventObjectConverter.Version.ORIGIN:
                    event = ChatEventManager.EventObjectConverter.convert(event, self.__text_button_commands[command]["event_version"])

                # Подготовка объекта вызова
                callback_object = {
                    "event": event,
                    "payload": payload,
                    "vk_api": self.__vk_api,
                    "manager": self,
                    "db": self.__db,
                    "output": output
                }

                callback = self.__text_button_commands[command]["callback"]
                callback_args = [callback_object] + self.__text_button_commands[command]["args"]
                callback(*callback_args)
            else:
                raise ChatEventManager.UnknownCommandException('Command \'{}\' not found'.format(command), command)
        else:
            raise ChatEventManager.UnknownCommandException('Event not supported', '')

    # Запуск обработки Callback Button команды
    def run_callback_button_command(self, event, output):
        payload = PayloadParser(event["object"]["payload"])
        command = payload.get_str(0, '')
        if self.is_callback_button_command(command):
            if not self.__callback_button_commands[command]['ignore_db'] and not self.__db.is_exists:
                raise ChatEventManager.DatabaseException('Command \'{}\' requires document in Database'.format(command))

            # Если версия Event'а не совпадает с оригиналом, то конвертируем в нужную версию, для обратной совместимости
            if self.__callback_button_commands[command]["event_version"] != ChatEventManager.EventObjectConverter.Version.ORIGIN:
                event = ChatEventManager.EventObjectConverter.convert(event, self.__callback_button_commands[command]["event_version"])

            # Подготовка объекта вызова
            callback_object = {
                "event": event,
                "payload": payload,
                "vk_api": self.__vk_api,
                "manager": self,
                "db": self.__db,
                "output": output
            }

            callback = self.__callback_button_commands[command]["callback"]
            callback_args = [callback_object] + self.__callback_button_commands[command]["args"]
            callback(*callback_args)
        else:
            raise ChatEventManager.UnknownCommandException('Command \'{}\' not found'.format(command), command)

    # Получение списка Message команд
    @property
    def message_command_list(self):
        return list(self.__message_commands)

    # Получение списка Text Button команд
    @property
    def text_button_command_list(self):
        return list(self.__text_button_commands)

    # Получение списка Callback Button команд
    @property
    def callback_button_command_list(self):
        return list(self.__callback_button_commands)

    #############################
    #############################
    # Метод ведения статистики

    def __stats_commit(self, user_id):
        if self.__db.is_exists:
            self.__chat_stats.commit(user_id)
        else:
            self.__db.check()
            if self.__db.is_exists:
                self.__chat_stats.commit(user_id)

    def __stats_command(self):
        # Обновление системной статистики
        current_date = datetime.utcnow().strftime("%Y-%m-%d")
        collection = self.__db.get_collection("system_stats")
        collection.update_one({"date": current_date}, {"$inc": {"command_used_count": 1}}, upsert=True)

        # Обновление статистики пользователя
        self.__chat_stats.update('command_used_count', 1)

    def __stats_button(self):
        # Обновление системной статистики
        current_date = datetime.utcnow().strftime("%Y-%m-%d")
        collection = self.__db.get_collection("system_stats")
        collection.update_one({"date": current_date}, {"$inc": {"button_pressed_count": 1}}, upsert=True)

        # Обновление статистики пользователя
        self.__chat_stats.update('button_pressed_count', 1)

    def __stats_message_handler(self):
        # Обновление системной статистики
        current_date = datetime.utcnow().strftime("%Y-%m-%d")
        collection = self.__db.get_collection("system_stats")
        collection.update_one({"date": current_date}, {"$inc": {"message_handler_processed_count": 1}}, upsert=True)

    def __stats_message_new(self):
        if self.__event["object"]["message"]["from_id"] > 0:
            self.__chat_stats.update_if_commited_by_last_user('msg_count_in_succession', 1)
            self.__chat_stats.update('msg_count', 1)
            self.__chat_stats.update('symbol_count', len(self.__event["object"]["message"]["text"]))

            attachment_update = {}
            for attachment in self.__event["object"]["message"]["attachments"]:
                if attachment["type"] == 'sticker':
                    if 'sticker_count' in attachment_update:
                        attachment_update['sticker_count'] += 1
                    else:
                        attachment_update['sticker_count'] = 1
                elif attachment["type"] == 'photo':
                    if 'photo_count' in attachment_update:
                        attachment_update['photo_count'] += 1
                    else:
                        attachment_update['photo_count'] = 1
                elif attachment["type"] == 'video':
                    if 'video_count' in attachment_update:
                        attachment_update['video_count'] += 1
                    else:
                        attachment_update['video_count'] = 1
                elif attachment["type"] == 'audio_message':
                    if 'audio_msg_count' in attachment_update:
                        attachment_update['audio_msg_count'] += 1
                    else:
                        attachment_update['audio_msg_count'] = 1
                elif attachment["type"] == 'audio':
                    if 'audio_count' in attachment_update:
                        attachment_update['audio_count'] += 1
                    else:
                        attachment_update['audio_count'] = 1
            for k, v in attachment_update.items():
                self.__chat_stats.update(k, v)

    # Метод обработки события
    def handle(self) -> bool:
        output = OutputSystem(self.__vk_api)

        if self.__event["type"] == 'message_new':
            if self.__event["object"]["message"]["from_id"] <= 0:
                return False

            self.__stats_message_new()  # Система отслеживания статистики

            # Попытка обработки события, как кнопку
            try:
                self.run_text_button_command(self.__event, output)

                # Система отслеживания статистики
                self.__stats_button()
                # Делаем коммит статистики, если беседа зарегистрирована
                self.__stats_commit(self.__event["object"]["message"]["from_id"])

                return True
            except ChatEventManager.DatabaseException:
                keyboard = KeyboardBuilder(KeyboardBuilder.INLINE_TYPE)
                keyboard.callback_button('Зарегистрировать', ['bot_reg'], KeyboardBuilder.POSITIVE_COLOR)
                keyboard = keyboard.build()
                output.messages_send(peer_id=self.__event["object"]["message"]["peer_id"], message=DEFAULT_MESSAGES.MESSAGE_NOT_REGISTERED, forward=bot.reply_to_message_by_event(self.__event), keyboard=keyboard)
                return False
            except ChatEventManager.UnknownCommandException:
                # Если команда не найдена, то продолжаем выполенение
                pass
            except:
                logname = datetime.utcfromtimestamp(time.time() + 10800).strftime("%d%m%Y-{}".format(generate_random_string(5, uppercase=False)))
                trace = traceback.format_exc()
                logpath = os.path.join(SYSTEM_PATHS.EXEC_LOG_DIR, "{}.log".format(logname))
                write_log(logpath, "Event:\n{}\n\n{}".format(json.dumps(self.__event, indent=4, ensure_ascii=False), trace[:-1]))
                keyboard = KeyboardBuilder(KeyboardBuilder.INLINE_TYPE)
                keyboard.callback_button("Репорт", ['report_error', logname], KeyboardBuilder.POSITIVE_COLOR)
                output.messages_send(peer_id=self.__event["object"]["message"]["peer_id"],
                                        message=DEFAULT_MESSAGES.MESSAGE_EXECUTION_ERROR.format(logname=logname),
                                        keyboard=keyboard.build())
                return False

            # Попытка обработки события, как текстовую команду
            try:
                self.run_message_command(self.__event, output)

                # Система отслеживания статистики
                self.__stats_command()
                # Делаем коммит статистики, если беседа зарегистрирована
                self.__stats_commit(self.__event["object"]["message"]["from_id"])

                return True
            except ChatEventManager.DatabaseException:
                keyboard = KeyboardBuilder(KeyboardBuilder.INLINE_TYPE)
                keyboard.callback_button('Зарегистрировать', ['bot_reg'], KeyboardBuilder.POSITIVE_COLOR)
                keyboard = keyboard.build()
                output.messages_send(peer_id=self.__event["object"]["message"]["peer_id"], message=DEFAULT_MESSAGES.MESSAGE_NOT_REGISTERED, forward=bot.reply_to_message_by_event(self.__event), keyboard=keyboard)
                return False
            except ChatEventManager.UnknownCommandException:
                # Если команда не найдена, то продолжаем выполенение
                pass
            except:
                # Логирование непредвиденной ошибки в файл
                logname = datetime.utcfromtimestamp(time.time() + 10800).strftime("%d%m%Y-{}".format(generate_random_string(5, uppercase=False)))
                trace = traceback.format_exc()
                logpath = os.path.join(SYSTEM_PATHS.EXEC_LOG_DIR, "{}.log".format(logname))
                write_log(logpath, "Event:\n{}\n\n{}".format(json.dumps(self.__event, indent=4, ensure_ascii=False), trace[:-1]))
                keyboard = KeyboardBuilder(KeyboardBuilder.INLINE_TYPE)
                keyboard.callback_button("Репорт", ['report_error', logname], KeyboardBuilder.POSITIVE_COLOR)
                output.messages_send(peer_id=self.__event["object"]["message"]["peer_id"],
                                        message=DEFAULT_MESSAGES.MESSAGE_EXECUTION_ERROR.format(logname=logname),
                                        keyboard=keyboard.build())
                return False

            # Обработка сообщений вне командного пространства
            handler_result = False
            if len(self.__message_handlers) > 0:
                for handler in self.__message_handlers:
                    if handler['ignore_db'] or self.__db.is_exists:
                        # Если версия Event'а не совпадает с оригиналом, то конвертируем в нужную версию, для обратной совместимости
                        if handler["event_version"] == ChatEventManager.EventObjectConverter.Version.ORIGIN:
                            event = self.__event
                        else:
                            event = ChatEventManager.EventObjectConverter.convert(self.__event, handler["event_version"])

                        # Подготовка объекта вызова
                        callback_object = {
                            "event": event,
                            "vk_api": self.__vk_api,
                            "manager": self,
                            "db": self.__db,
                            "output": output
                        }

                        if handler['callback'](callback_object):
                            handler_result = True
                            self.__stats_message_handler()      # Система отслеживания статистики
                            break

            # Делаем коммит статистики, если беседа зарегистрирована
            self.__stats_commit(self.__event["object"]["message"]["from_id"])

            return handler_result

        elif self.__event["type"] == 'message_event':
            try:
                self.run_callback_button_command(self.__event, output)

                # Система отслеживания статистики
                self.__stats_button()
                # Делаем коммит статистики, если беседа зарегистрирована
                self.__stats_commit(self.__event["object"]["user_id"])

                return True
            except ChatEventManager.DatabaseException:
                output.show_snackbar(self.__event["object"]["event_id"], self.__event["object"]["user_id"], self.__event["object"]["peer_id"], DEFAULT_MESSAGES.SNACKBAR_NOT_REGISTERED)
                return False
            except ChatEventManager.UnknownCommandException:
                output.show_snackbar(self.__event["object"]["event_id"], self.__event["object"]["user_id"], self.__event["object"]["peer_id"], DEFAULT_MESSAGES.SNACKBAR_UNKNOWN_COMMAND)
                return False
            except:
                # Логирование непредвиденной ошибки в файл
                logname = datetime.utcfromtimestamp(time.time() + 10800).strftime("%d%m%Y-{}".format(generate_random_string(5, uppercase=False)))
                trace = traceback.format_exc()
                logpath = os.path.join(SYSTEM_PATHS.EXEC_LOG_DIR, "{}.log".format(logname))
                write_log(logpath, "Event:\n{}\n\n{}".format(json.dumps(self.__event, indent=4, ensure_ascii=False), trace[:-1]))
                keyboard = KeyboardBuilder(KeyboardBuilder.INLINE_TYPE)
                keyboard.callback_button("Репорт", ['report_error', logname], KeyboardBuilder.POSITIVE_COLOR)
                output.messages_edit(peer_id=self.__event["object"]["peer_id"],
                                        conversation_message_id=self.__event["object"]["conversation_message_id"],
                                        message=DEFAULT_MESSAGES.MESSAGE_EXECUTION_ERROR.format(logname=logname),
                                        keyboard=keyboard.build())
                return False

    # Метод завершения обработки события
    def finish(self):
        self.__db.disconnect()      # Отключаемся от базы данных


class OutputSystem:
    #############################
    #############################
    # Конструктор

    def __init__(self, vk_api: VK_API):
        self.__vk_api = vk_api

        self.__messages_send_request_count = 0  # Количество вызовов messages_send
        self.__messages_edit_request_count = 0  # Количество вызовов messages_edit
        self.__show_snackbar_request_count = 0  # Количество вызовов show_snackbar

    @property
    def messages_send_request_count(self):
        return self.__messages_send_request_count

    @property
    def messages_edit_request_count(self):
        return self.__messages_edit_request_count

    @property
    def show_snackbar_request_count(self):
        return self.__show_snackbar_request_count

    # Метод messages.send
    def messages_send(self, **kwargs) -> dict:
        reqm = {}
        vk_vars1 = VKVariable()
        vk_vars2 = VKVariable()

        script = ''
        pscript = ''
        for key, value in kwargs.items():
            if key == 'script':
                script = value
            elif key == 'pscript':
                pscript = value
            else:
                if isinstance(value, VKVariable.Multi):
                    vk_vars2.var('reqm.'+key, value)
                else:
                    reqm[key] = value

        reqm['random_id'] = 0   # Устаналивам random_id

        vk_vars1.var('var reqm', reqm)

        self.__messages_send_request_count += 1  # Увеличиваем счетчик вызовов
        return self.__vk_api.execute("{}{}{}API.messages.send(reqm);{}return true;".format(script, vk_vars1(), vk_vars2(), pscript))

    # Метод messages.edit
    def messages_edit(self, **kwargs) -> dict:
        reqm = {}
        vk_vars1 = VKVariable()
        vk_vars2 = VKVariable()

        script = ''
        pscript = ''
        for key, value in kwargs.items():
            if key == 'script':
                script = value
            elif key == 'pscript':
                pscript = value
            else:
                if isinstance(value, VKVariable.Multi):
                    vk_vars2.var('reqm.'+key, value)
                else:
                    reqm[key] = value

        vk_vars1.var('var reqm', reqm)

        self.__messages_edit_request_count += 1     # Увеличиваем счетчик вызовов
        return self.__vk_api.execute("{}{}{}API.messages.edit(reqm);{}return true;".format(script, vk_vars1(), vk_vars2(), pscript))

    def show_snackbar(self, event_id: str, user_id: int, peer_id: int, text: str, script: str = '', pscript: str = '') -> dict:
        event_data = json.dumps({'type': 'show_snackbar', 'text': text}, ensure_ascii=False, separators=(',', ':'))
        reqm = json.dumps({'event_id': event_id, 'user_id': user_id, 'peer_id': peer_id, 'event_data': event_data},  ensure_ascii=False, separators=(',', ':'))

        self.__show_snackbar_request_count += 1     # Увеличиваем счетчик вызовов
        return self.__vk_api.execute('{}API.messages.sendMessageEventAnswer({});{}return true;'.format(script, reqm, pscript))


# Класс Продвинутой системы вывода
class AdvancedOutputSystem:
    def __init__(self, output: OutputSystem, event: dict, db: ChatDatabase):
        self.__output = output
        self.__db = db
        self.__event = event

        self.__prefs = {
            'reply_to_message': True,
            'disable_mentions': True
        }

        self.__prepare_appeal_code()

    def __prepare_appeal_code(self):
        if self.__event["type"] == 'message_new':
            user_id = self.__event["object"]["message"]["from_id"]
        elif self.__event["type"] == 'message_event':
            user_id = self.__event["object"]["user_id"]
        projection = {'_id': 0, 'chat_settings.user_nicknames.id{}'.format(user_id): 1}
        query = self.__db.find(projection=projection)
        user_nickname = ValueExtractor(query).get('chat_settings.user_nicknames.id{}'.format(user_id), None)
        if user_nickname is not None:
            self.__appeal_code = 'var appeal="@id{userid} ({nickname}), ";'.format(userid=user_id, nickname=user_nickname)
        else:
            self.__appeal_code = 'var appeal="";'

    def prefs(self, **kwargs):
        for k, v in kwargs.items():
            self.__prefs[k] = v

    def messages_send(self, **reqm):
        if self.__event["type"] == 'message_new':
            # Добавление дополнительного кода
            reqm['script'] = self.__appeal_code + reqm.get('script', '')

            # Отключение уведомлений от упоминаний, если так задано в настройках
            if self.__prefs['disable_mentions']:
                reqm['disable_mentions'] = True

            reqm['peer_id'] = self.__event["object"]["message"]["peer_id"]
            if self.__prefs['reply_to_message']:
                forward = {
                    'peer_id': self.__event["object"]["message"]["peer_id"],
                    'conversation_message_ids': [self.__event["object"]["message"]["conversation_message_id"]],
                    'is_reply': True
                }
                reqm['forward'] = json.dumps(forward, ensure_ascii=False, separators=(',', ':'))

            return self.__output.messages_send(**reqm)

    def messages_edit(self, **reqm):
        if self.__event["type"] == 'message_event':
            # Добавление дополнительного кода
            reqm['script'] = self.__appeal_code + reqm.get('script', '')

            # Отключение уведомлений от упоминаний, если так задано в настройках
            if self.__prefs['disable_mentions']:
                reqm['disable_mentions'] = True

            reqm['peer_id'] = self.__event["object"]["peer_id"]
            reqm['conversation_message_id'] = self.__event["object"]["conversation_message_id"]
            reqm['keep_forward_messages'] = self.__prefs['reply_to_message']

            return self.__output.messages_edit(**reqm)

    def show_snackbar(self, **reqm):
        if self.__event["type"] == 'message_event':
            reqm['peer_id'] = self.__event["object"]["peer_id"]
            reqm['user_id'] = self.__event["object"]["user_id"]
            reqm['event_id'] = self.__event["object"]["event_id"]

            return self.__output.show_snackbar(**reqm)