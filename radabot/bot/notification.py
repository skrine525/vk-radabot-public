import threading, time
from pymongo import MongoClient
from radabot.core.system import Config, ChatDatabase
from radabot.core.vk import VK_API


UPDATE_LABEL_STRING = "#обновление"     # Метка, сигнализирующая, что пост - уведомлении об обновлении
NOTIFICATION_SENDING_COOLDOWN = 2       # Время в секундах между рассылками
DATABASE_FIND_LIMIT = 100               # Максимальное количество записей, которое можно получить из базы данных

# Первоночальная функция запуска рассылки
def start_notify(vk_api: VK_API, event: dict):
    if event["object"]["post_type"] == "post":
        labeled_substring = event["object"]["text"][:20].lower()
        if(labeled_substring.find(UPDATE_LABEL_STRING) >= 0):
            # Если найдена метка, то запускаем поток рассылки
            thread = threading.Thread(target=notification_thread, daemon=False, args=[vk_api, event])
            thread.start()

def notification_thread(vk_api: VK_API, event: dict):
    # Уведомление суперпользователя о начале рассылки
    owner_id = event["object"]["owner_id"]
    post_id = event["object"]["id"]
    post_attachment = f"wall{owner_id}_{post_id}"
    post_link = f"vk.com/{post_attachment}"
    vk_api.call("messages.send", {"peer_id": Config.get("SUPERUSER_ID"), "message": f"✅Рассылка поста запущена.\n{post_link}", "random_id": 0})

    # Подключаемся к базе данных
    mongo_client = MongoClient(Config.get('DATABASE_HOST'), Config.get('DATABASE_PORT'))
    database = mongo_client[Config.get('DATABASE_NAME')]
    collection = database[ChatDatabase.CHAT_DATA_COLLECTION_NAME]

    # Рассылка
    skip_count = 0      # Количество записей, необходимых пропустить
    can_notify = True
    while can_notify:
        chats = collection.find({}, projection={"_id": 0, "chat_id": 1}, skip=skip_count, limit=DATABASE_FIND_LIMIT)
        peer_ids = []
        for chat in chats:
            if "chat_id" in chat:
                peer_ids.append(str(chat["chat_id"] + 2000000000))
            skip_count += 1
        if len(peer_ids) > 0:
            vk_api.call("messages.send", {"peer_ids": ",".join(peer_ids), "attachment": post_attachment, "random_id": 0})
            time.sleep(NOTIFICATION_SENDING_COOLDOWN)
        else:
            can_notify = False

    # Уведомление суперпользователя о конце рассылки
    vk_api.call("messages.send", {"peer_id": Config.get("SUPERUSER_ID"), "message": f"✅Рассылка поста окончена.\n{post_link}", "random_id": 0})

    # Закрываем соединение с базой данных
    mongo_client.close()

