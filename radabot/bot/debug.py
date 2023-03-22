import gc
from radabot.core.io import AdvancedOutputSystem, ChatEventManager
from ..core.system import Config
from radabot.core.vk import KeyboardBuilder, VKVariable


def initcmd(manager: ChatEventManager):
    # Если пользователь не является суперпользователем, то не инициализируем отладочные команды
    if (manager.event["type"] == 'message_new' and manager.event["object"]["message"]["from_id"] != Config.get("SUPERUSER_ID")) or (manager.event["type"] == 'message_event' and manager.event["object"]["user_id"] != Config.get("SUPERUSER_ID")):
        return

    manager.add_message_command('!error', ErrorCommand.message_command)
    manager.add_message_command('!gc', GarbageCollector.message_command)


class ErrorCommand:
    @staticmethod
    def message_command(callback_object: dict):
        raise Exception()


class GarbageCollector:
    @staticmethod
    def message_command(callback_object: dict):
        event = callback_object["event"]
        db = callback_object["db"]
        output = callback_object["output"]

        gc.collect()

        aos = AdvancedOutputSystem(output, event, db)
        message_text = "✅Очищаю мусор..."
        aos.messages_send(message=VKVariable.Multi('var', 'appeal', 'str', message_text))