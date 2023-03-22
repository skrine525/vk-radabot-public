# Module Level 0
import subprocess
import string, random, json, os, time, shlex
from datetime import datetime
from pymongo import MongoClient


# Переменная системных путей
class SYSTEM_PATHS:
    # Директории
    DATA_DIR = 'data'
    LOG_DIR = 'log'
    TMP_DIR = 'tmp'
    EXEC_LOG_DIR = os.path.join(LOG_DIR, 'exec')
    FONTS_DIR = os.path.join(DATA_DIR, 'fonts')

    # Файлы
    CONFIG_FILE = os.path.join(DATA_DIR, 'config.json')
    MANAGER_DATA_FILE = os.path.join(DATA_DIR, 'manager.json')
    LONGPOLL_LOG_FILE = os.path.join(LOG_DIR, 'longpoll.log')
    ERROR_LOG_FILE = os.path.join(LOG_DIR, 'error.log')


class ChatDatabase:
    CHAT_DATA_COLLECTION_NAME = "chat_main"         # Название коллекция с данными чатов

    @staticmethod
    def get_chat_db_filter(_id: int) -> dict:
        if _id > 2000000000:
            _id = _id - 2000000000
        return {'_id': 'chat{}'.format(_id)}

    def __init__(self, database_host: str, database_port: int, database_name: str, peer_id: int):
        self.__mongo_client = MongoClient(database_host, database_port)
        self.__database = self.__mongo_client[database_name]
        self.__main_collection = self.__database[ChatDatabase.CHAT_DATA_COLLECTION_NAME]
        self.__chat_id = peer_id - 2000000000
        self.__main_filter = ChatDatabase.get_chat_db_filter(self.__chat_id)

        self.__is_exists = False
        self.__owner_id = 0

        # Выполняем проверку базы данных
        self.check()

    @property
    def is_exists(self):
        return self.__is_exists

    @property
    def owner_id(self):
        return self.__owner_id

    @property
    def chat_id(self):
        return self.__chat_id

    @property
    def main_filter(self):
        return self.__main_filter

    def get_collection(self, name = None):
        if name is None:
            return self.__database[self.__main_collection]
        else:
            return self.__database[name]

    def find(self, *args, **kwargs):
        return self.__main_collection.find_one(self.__main_filter, *args, **kwargs)

    def update(self, *args, **kwargs):
        return self.__main_collection.update_one(self.__main_filter, *args, **kwargs)

    def check(self):
        result = self.__main_collection.find_one(self.__main_filter, projection={'_id': 0, 'owner_id': 1})
        if result is not None:
            self.__is_exists = True

            extractor = ValueExtractor(result)
            self.__owner_id = extractor.get('owner_id')

    def disconnect(self):
        self.__mongo_client.close()


class ValueExtractor:
    class InvalidArgumentException(Exception):
        def __init__(self, msg: str):
            self.msg = msg

    def __init__(self, data):
        if isinstance(data, dict) or isinstance(data, list):
            self.__data = data
        else:
            raise ValueExtractor.InvalidArgumentException(r"argument 'data' must be dict or list")

    def get(self, path, default=None):
        if isinstance(path, str):
            path_list = path.split('.')
        elif isinstance(path, list):
            path_list = path
        else:
            raise ValueExtractor.InvalidArgumentException(r"argument 'path' must be list or str")

        value = self.__data
        for key in path_list:
            if isinstance(value, dict):
                value = value.get(key, None)
                if value is None:
                    return default
            elif isinstance(value, list):
                try:
                    value = value[int(key)]
                except (IndexError, ValueError):
                    return default
            else:
                return default
        return value


class ArgumentParser:
    def __init__(self, line: str):
        try:
            self.__args = shlex.split(line, posix=False)
        except ValueError:
            # Вызывается, когда есть незакрытая ковычка
            self.__args = []

    @property
    def count(self):
        return len(self.__args)

    @property
    def args_dict(self):
        return self.__args

    def clear_index(self, index: int) -> bool:
        try:
            self.__args.pop(index)
            return True
        except IndexError:
            return False

    def get_str(self, index: int, default: str = None) -> str:
        try:
            return str(self.__args[index])
        except (IndexError, ValueError):
            return default

    def get_int(self, index: int, default: int = None) -> int:
        try:
            return int(self.__args[index])
        except (IndexError, ValueError):
            return default

    def get_float(self, index: int, default: float = None) -> float:
        try:
            return float(self.__args[index])
        except (IndexError, ValueError):
            return default

    def get_words(self, start = None, end = None):
        if isinstance(start, int) and isinstance(end, int):
            return " ".join(self.__args[start:end])
        elif isinstance(start, int) and not isinstance(end, int):
            return " ".join(self.__args[start:])
        elif not isinstance(start, int) and isinstance(end, int):
            return " ".join(self.__args[:end])
        else:
            return ""


class PayloadParser:
    def __init__(self, payload: list):
        self.__payload = payload

    @property
    def count(self):
        return len(self.__payload)

    def get_str(self, index: int, default: str = None) -> str:
        try:
            return str(self.__payload[index])
        except (IndexError, ValueError):
            return default

    def get_int(self, index: int, default: int = None) -> int:
        try:
            return int(self.__payload[index])
        except (IndexError, ValueError):
            return default

    def get_float(self, index: int, default: float = None) -> float:
        try:
            return float(self.__payload[index])
        except (IndexError, ValueError):
            return default


class PageBuilder:
    # Исключение
    class PageNumberException(Exception):
        def __init__(self, message: str):
            self.message = message

    def __init__(self, data: list, page_size: int):
        self.__data = data
        self.__size = page_size

        self.__max_number = len(self.__data) // self.__size
        if (len(self.__data) % self.__size) != 0:
            self.__max_number += 1

    def __call__(self, number: int):
        page = []

        min_index = self.__size * number - self.__size
        max_index = self.__size * number
        if self.__size * number >= len(self.__data):
            max_index = len(self.__data)

        if self.__max_number >= number > 0:
            for i in range(min_index, max_index):
                page.append(self.__data[i])
        else:
            raise PageBuilder.PageNumberException("Page number out of range [1..{}]".format(self.__max_number))

        return page

    @property
    def max_number(self):
        return self.__max_number


class SelectedUserParser:
    def __init__(self):
        # Пересланные сообщения
        self.__reply_message = None

        # Аргументы команды
        self.__args_parser = None
        self.__args_index = 0
        self.__clear_index = False
        self.__args_parse_numeric = False
        self.__args_parse_mention = False

    def set_reply_message(self, reply_message: list):
        self.__reply_message = reply_message

    def set_argument_parser(self, parser: ArgumentParser, index: int, clear_index: bool = True, parse_numeric: bool = True, parse_mention: bool = True):
        self.__args_parser = parser
        self.__args_index = index
        self.__clear_index = clear_index
        self.__args_parse_numeric = parse_numeric
        self.__args_parse_mention = parse_mention

    def member_id(self, default: int = 0):
        # Пытаемся извлечь member_id из пересланных сообщений
        try:
            if self.__reply_message is not None:
                return self.__reply_message["from_id"]
        except IndexError:
            pass

        if self.__args_parser is not None:
            argument = self.__args_parser.get_str(self.__args_index, None)
            if argument is not None:
                if self.__args_parse_numeric:
                    # Пытаемся извлечь member_id из аргумента и превратить в int
                    try:
                        member_id = int(argument)
                        if member_id > 0:
                            if self.__clear_index:
                                self.__args_parser.clear_index(self.__args_index)
                            return member_id
                    except ValueError:
                        pass

                if self.__args_parse_mention:
                    # Пытаемся извлечь member_id с помощью парсинга аргумента с упоминанием
                    str_number = ''
                    for i in range(3, len(argument)):
                        symbol = argument[i]
                        if symbol == '|':
                            break
                        else:
                            str_number += symbol

                    try:
                        member_id = int(str_number)
                        if member_id > 0:
                            if self.__clear_index:
                                self.__args_parser.clear_index(self.__args_index)
                            return member_id
                    except ValueError:
                        pass

        # Если не получилось извлечь member_id, то возвращаем default
        return default


class CommandHelpBuilder:
    def __init__(self, message: str):
        self.__message = message
        self.__commands = []
        self.__examples = []

    def command(self, text: str, *args, **kwargs):
        self.__commands.append(text.format(*args, **kwargs))

    def example(self, text: str, *args, **kwargs):
        self.__examples.append(text.format(*args, **kwargs))

    def build(self):
        message = self.__message
        if len(self.__commands) > 0:
            message += "\n\nИспользуйте:\n• "
            message += '\n• '.join(self.__commands)
        if len(self.__examples) > 0:
            message += "\n\nНапример:\n• "
            message += '\n• '.join(self.__examples)
        return message


# Класс для работы с Config файлом
class Config:
    __data = {}
    __DEFAULT_DATA = {
        'PHP_COMMAND': '',
        'VK_GROUP_TOKEN': '',
        'VK_GROUP_ID': 0,
        'VK_USER_TOKEN': '',
        'VOICERSS_KEY': '',
        'GIPHY_API_TOKEN': '',
        'RANDOMORG_API_KEY': '',
        'SUPERUSER_ID': 0,
        'DATABASE_HOST': '',
        'DATABASE_PORT': 0,
        'DATABASE_NAME': ''
    }
    
    # Исключение Config файла
    class FileException(Exception):
        def __init__(self, message: str):
            self.message = message

    @staticmethod
    def read_file():
        if(os.path.isfile(SYSTEM_PATHS.CONFIG_FILE)):
            f = open(SYSTEM_PATHS.CONFIG_FILE, encoding='utf-8')
            Config.__data = json.loads(f.read())
            f.close()
        else:
            f = open(SYSTEM_PATHS.CONFIG_FILE, 'w', encoding='utf-8')
            f.write(json.dumps(Config.__DEFAULT_DATA, indent=4))
            f.close()
            raise Config.FileException("File 'config.json' does not exist")

    @staticmethod
    def get(name):
        return Config.__data.get(name, None)


class ManagerData:
    __data = {}

    # Метод чтения файла manager.json
    @staticmethod
    def read_file():
        f = open(SYSTEM_PATHS.MANAGER_DATA_FILE, encoding='utf-8')
        ManagerData.__data = json.loads(f.read())
        f.close()

    # Возвращение данных user_permissions
    @staticmethod
    def get_user_permissions_data():
        return ManagerData.__data.get('user_permissions', {})

    # Возвращение данных chat_modes
    @staticmethod
    def get_chat_modes_data():
        return ManagerData.__data.get('chat_modes', {})


class PHPCommandIntegration:
    message_commands = []
    callback_button_commands = []
    text_button_commands = []

    @staticmethod
    def init():
        #subprocess.Popen([Config.get("PHP_COMMAND"), "radabot-php.php", "int", ""]).communicate()
        #path_to_php_integration = os.path.join(SYSTEM_PATHS.TMP_DIR, 'php_integration.txt')
        path_to_php_integration = os.path.join(SYSTEM_PATHS.DATA_DIR, 'php_integration.txt')
        if os.path.exists(path_to_php_integration):
            f = open(path_to_php_integration, encoding='utf-8')
            php_integration = f.read()
            f.close()
            #os.remove(path_to_php_integration)
            splited_php_integration = php_integration.split("\n")
            PHPCommandIntegration.message_commands = splited_php_integration[0].split(';')
            PHPCommandIntegration.callback_button_commands = splited_php_integration[1].split(';')
            PHPCommandIntegration.text_button_commands = splited_php_integration[2].split(';')


# Функция конвертирования числа в эмодзи
def int2emoji(number: int):
    numbers = []
    while number > 0:
        numbers.append(number % 10)
        number = number // 10
    numbers.reverse()

    # Если массив пустой, то добавляем 0
    if len(numbers) == 0:
        numbers.append(0)

    emoji = ['0&#8419;', '1&#8419;', '2&#8419;', '3&#8419;', '4&#8419;', '5&#8419;', '6&#8419;', '7&#8419;', '8&#8419;',
             '9&#8419;']
    emoji_str = ""
    for i in numbers:
        emoji_str += emoji[i]

    return emoji_str

# Генерация случайной строки
def generate_random_string(length, uppercase=True, lowercase=True, numbers=True):
    letters = ''
    if uppercase and lowercase:
        letters = string.ascii_letters
    elif uppercase:
        letters = string.ascii_uppercase
    elif lowercase:
        letters = string.ascii_lowercase
    if numbers:
        letters += '0123456789'

    return ''.join(random.choice(letters) for _ in range(length))

# Возвращает объект сообщения, на которое ответили, или объект первого пересланного сообщения
def get_reply_message_from_event(event: dict):
    reply_message = None
    if 'reply_message' in event["object"]["message"]:
        reply_message = event["object"]["message"]["reply_message"]
    else:
        try:
            reply_message = event["object"]["message"]["fwd_messages"][0]
        except IndexError:
            pass
    return reply_message

# Возвращает объект с самым качественным изображением во вложении
def get_high_resolution_attachment_photo(attach: dict):
    if attach["type"] == "photo":
        # Ищет самое качественное фото по мере уменьшения качества
        for t in ["w", "z", "y", "x", "m", "s"]:
            for p in attach["photo"]["sizes"]:
                if p["type"] == t:
                    return p
    
    # Если ничего не найдено, то возвращает None
    return None


# Метод журналирования
def write_log(filename: str, text: str):
    f = open(filename, "a", encoding='utf-8')
    tm = time.time() + 10800
    dt = datetime.utcfromtimestamp(tm).strftime("%d-%b-%Y %X Russia/Moscow")
    f.write("[{}] {}\n".format(dt, text))
    f.close()

# Функция инициализации системных директорий
def initdir():
    if not os.path.exists(SYSTEM_PATHS.TMP_DIR):
        os.mkdir(SYSTEM_PATHS.TMP_DIR)

    if not os.path.exists(SYSTEM_PATHS.LOG_DIR):
        os.mkdir(SYSTEM_PATHS.LOG_DIR)

    if not os.path.exists(SYSTEM_PATHS.EXEC_LOG_DIR):
        os.mkdir(SYSTEM_PATHS.EXEC_LOG_DIR)