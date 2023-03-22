import datetime, math, time, requests, os, threading, seam_carving, json
import numpy as np
from PIL import Image, ImageFont, ImageDraw
from radabot.core.bot import DEFAULT_MESSAGES
from radabot.core.io import ChatEventManager, AdvancedOutputSystem
from radabot.core.manager import ChatModes, UserPermissions
from radabot.core.system import SYSTEM_PATHS, ArgumentParser, CommandHelpBuilder, ManagerData, PageBuilder, ValueExtractor, generate_random_string, get_high_resolution_attachment_photo, get_reply_message_from_event, int2emoji
from radabot.core.vk import KeyboardBuilder, VKVariable


def initcmd(manager: ChatEventManager):
    manager.add_message_command('!мемы', CustomMemes.message_command)
    manager.add_message_command('!жмых', FunSeamCarving.message_command)
    manager.add_message_command('!цитата', FunQuote.message_command)

    manager.add_callback_button_command('fun_memes', CustomMemes.callback_button_command)


class CustomMemes:
    SHOW_SIZE = 10
    MAX_MEMES_COUNT = 100

    def message_command(callback_object: dict):
        event = callback_object["event"]
        args = callback_object["args"]
        db = callback_object["db"]
        output = callback_object["output"]
        manager = callback_object["manager"]
        vk_api = callback_object["vk_api"]

        aos = AdvancedOutputSystem(output, event, db)

        # Проверка режима allow_memes
        chat_modes = manager.chat_modes
        if not chat_modes.get('allow_memes'):
            mode_label = ChatModes.get_label('allow_memes')
            CustomMemes.__print_error_text(aos, 'Режим {} отключен.'.format(mode_label))
            return

        subcommand = args.get_str(1, '').lower()
        if subcommand == 'показ':
            # Подгружаем все мемы базы данных
            db_result = db.find(projection={'_id': 0, 'fun.memes': 1})
            extractor = ValueExtractor(db_result)
            all_memes = extractor.get('fun.memes', [])

            if(len(all_memes) == 0):
                CustomMemes.__print_error_text(aos, 'В беседе нет мемов.')
                return

            names = list(all_memes)
            builder = PageBuilder(names, CustomMemes.SHOW_SIZE)
            number = args.get_int(2, 1)

            try:
                page = builder(number)
                text = 'Список мемов [{}/{}]:'.format(number, builder.max_number)
                for i in page:
                    text += "\n• " + i

                keyboard = KeyboardBuilder(KeyboardBuilder.INLINE_TYPE)
                if number > 1:
                    prev_number = number - 1
                    keyboard.callback_button("{} ⬅".format(int2emoji(prev_number)), ['fun_memes', event["object"]["message"]["from_id"], 1, prev_number], KeyboardBuilder.SECONDARY_COLOR)
                if number < builder.max_number:
                    next_number = number + 1
                    keyboard.callback_button("➡ {}".format(int2emoji(next_number)), ['fun_memes', event["object"]["message"]["from_id"], 1, next_number], KeyboardBuilder.SECONDARY_COLOR)
                keyboard.new_line()
                keyboard.callback_button('Закрыть', ['bot_cancel', event["object"]["message"]["from_id"]], KeyboardBuilder.NEGATIVE_COLOR)

                aos.messages_send(message=VKVariable.Multi('var', 'appeal', 'str', text), keyboard=keyboard.build())
            except PageBuilder.PageNumberException:
                aos.messages_send(message=VKVariable.Multi('var', 'appeal', 'str', '⛔Неверный номер страницы.'))
        elif subcommand == 'добав':
            user_permissions = UserPermissions(db, event["object"]["message"]["from_id"])
            if user_permissions.get('customize_memes'):
                meme_name = args.get_words(2)
                if meme_name != '':
                    # Ограничение длинны названия 15 символов
                    if len(meme_name) > 15:
                        CustomMemes.__print_error_text(aos, 'Название превышает 15 символов.')
                        return

                    # Ограничение на использование символа $
                    if meme_name.count('$') > 0:
                        CustomMemes.__print_error_text(aos, 'Символ \'$\' запрещен в названии мема.')
                        return

                    # Ограничение названий, эквивалентных Message командам
                    for command in manager.message_command_list:
                        if command == meme_name:
                            CustomMemes.__print_error_text(aos, 'Название мема не должно являться командой.')
                            return

                    # Подгружаем все мемы базы данных
                    db_result = db.find(projection={'_id': 0, 'fun.memes': 1})
                    extractor = ValueExtractor(db_result)
                    all_memes = extractor.get('fun.memes', [])

                    # Ограничение на количество мемов в беседе
                    if len(all_memes) >= CustomMemes.MAX_MEMES_COUNT:
                        CustomMemes.__print_error_text(aos, 'Максимальное количество мемов в беседе: 100 штук.')
                        return

                    # Ограничение, если мем с таким названием уже существует
                    if meme_name in all_memes:
                        CustomMemes.__print_error_text(aos, 'Мем с таким названием уже существует.')
                        return

                    # Ограничение, если не прикреплено вложение
                    try:
                        attachment = event["object"]["message"]["attachments"][0]
                    except IndexError:
                        CustomMemes.__print_error_text(aos, 'Для добавление мема необходимо прикрепить вложение.')
                        return

                    content = ''

                    if attachment["type"] == 'photo':
                        # Метод заключается в скачивании картинки, с последующим дропом на сервак, чтобы владельцем вложения стал бот
                        # Потому что access_token по всей видимости временный ¯\_(ツ)_/¯

                        # Скачиваем картинку
                        photo = get_high_resolution_attachment_photo(attachment)
                        img_path = os.path.join(SYSTEM_PATHS.TMP_DIR, "{}.jpg".format(generate_random_string(10, uppercase=False)))
                        img_req = requests.get(photo["url"])
                        img_file = open(img_path, "wb")
                        img_file.write(img_req.content)
                        img_file.close()

                        # Загружаем картинку назад в ВК
                        peer_id = event["object"]["message"]["peer_id"]
                        execute_data = vk_api.execute(f"return API.photos.getMessagesUploadServer({{peer_id:{peer_id}}});")["response"]
                        img_file = open(img_path, 'rb')
                        upload_result = requests.post(execute_data["upload_url"], files={'photo': img_file}).text
                        img_file.close()

                        # Сообщаем, что мем сохранен
                        doc = aos.messages_send(message=VKVariable.Multi('var', 'appeal', 'str', '✅Мем сохранен!'), pscript=f"return API.photos.saveMessagesPhoto({upload_result})[0];")["response"]

                        # Формируем объект вложения
                        content = "photo{}_{}".format(doc["owner_id"], doc["id"])
                    elif attachment["type"] == 'audio':
                        # Сообщаем, что мем сохранен
                        aos.messages_send(message=VKVariable.Multi('var', 'appeal', 'str', '✅Мем сохранен!'))

                        # Формируем объект вложения
                        content = 'audio{}_{}'.format(attachment["audio"]["owner_id"], attachment["audio"]["id"])
                    elif attachment["type"] == 'video':
                        if 'is_private' in attachment["video"]:
                            CustomMemes.__print_error_text(aos, 'Данной видео является приватным и не может быть использовано в меме.')
                            return
                        else:
                            # Сообщаем, что мем сохранен
                            aos.messages_send(message=VKVariable.Multi('var', 'appeal', 'str', '✅Мем сохранен!'))

                            # Формируем объект вложения
                            content = 'video{}_{}'.format(attachment["video"]["owner_id"], attachment["video"]["id"])
                    elif attachment["type"] == 'doc' and attachment["doc"]["ext"] == "gif":
                        # Скачиваем гифку
                        gif_path = os.path.join(SYSTEM_PATHS.TMP_DIR, "{}.gif".format(generate_random_string(10, uppercase=False)))
                        gif_req = requests.get(attachment["doc"]["url"])
                        gif_file = open(gif_path, "wb")
                        gif_file.write(gif_req.content)
                        gif_file.close()

                        # Загружаем гифку назад в ВК
                        peer_id = event["object"]["message"]["peer_id"]
                        execute_data = vk_api.execute(f"return API.docs.getMessagesUploadServer({{peer_id:{peer_id},type:\"doc\"}});")["response"]
                        gif_file = open(gif_path, 'rb')
                        upload_result = requests.post(execute_data["upload_url"], files={'file': gif_file}).json()
                        gif_file.close()
                        # TODO Удаление в tmp?

                        # Сообщаем, что мем сохранен
                        save_args = json.dumps({"file": upload_result["file"], "title": attachment["doc"]["title"]}, ensure_ascii=False, separators=(',', ':'))
                        doc = aos.messages_send(message=VKVariable.Multi('var', 'appeal', 'str', '✅Мем сохранен!'), pscript=f"return API.docs.save({save_args});")["response"]

                        # Формируем объект вложения
                        content = "doc{}_{}".format(doc["doc"]["owner_id"], doc["doc"]["id"])
                    else:
                        CustomMemes.__print_error_text(aos, 'Данный тип вложения не поддерживается.')

                    # Сохраняем мем в базу данных
                    meme = {
                        'owner_id': event["object"]["message"]["from_id"],
                        'content': content,
                        'date': math.trunc(time.time())
                    }
                    meme_path = 'fun.memes.{}'.format(meme_name)
                    db.update({'$set': {meme_path: meme}})
                else:
                    CustomMemes.__print_help_message_add(aos, args)
            else:
                message = VKVariable.Multi('var', 'appeal', 'str', CustomMemes.__get_error_message_you_have_no_rights())
                aos.messages_send(message=message)
        elif subcommand == 'удал':
            user_permissions = UserPermissions(db, event["object"]["message"]["from_id"])
            if user_permissions.get('customize_memes'):
                meme_name = args.get_str(2, '').lower()
                if meme_name != '':
                    # Подгружаем удаляемый мем
                    db_result = db.find(projection={'_id': 0, f'fun.memes.{meme_name}': 1})
                    extractor = ValueExtractor(db_result)
                    meme_data = extractor.get(f'fun.memes.{meme_name}', None)

                    if meme_data is None:
                        CustomMemes.__print_error_text(aos, f"Мема '{meme_name}' не существует.")
                    else:
                        db.update({"$unset": {f'fun.memes.{meme_name}': 0}})

                        # Сообщаем, что мем удален
                        aos.messages_send(message=VKVariable.Multi('var', 'appeal', 'str', '✅Мем удален!'))
                else:
                    CustomMemes.__print_help_message_del(aos, args)
            else:
                message = VKVariable.Multi('var', 'appeal', 'str', CustomMemes.__get_error_message_you_have_no_rights())
                aos.messages_send(message=message)
        elif subcommand == 'очис':
            user_permissions = UserPermissions(db, event["object"]["message"]["from_id"])
            if user_permissions.get('customize_memes'):
                # Подгружаем все мемы базы данных
                db_result = db.find(projection={'_id': 0, 'fun.memes': 1})
                extractor = ValueExtractor(db_result)
                all_memes = extractor.get('fun.memes', [])

                meme_count = len(all_memes)
                if meme_count > 0:
                    user_meme_count = args.get_int(2, 0)
                    if user_meme_count != 0:
                        if user_meme_count == meme_count:
                            db.update({"$unset": {f'fun.memes': 0}})

                            # Сообщаем, что мемы удален
                            aos.messages_send(message=VKVariable.Multi('var', 'appeal', 'str', '✅Все мемы удалены!'))
                        else:
                            CustomMemes.__print_help_message_cls(aos, args, meme_count)
                    else:
                        CustomMemes.__print_help_message_cls(aos, args, meme_count)
                else:
                    CustomMemes.__print_error_text(aos, "В беседе нет мемов.")
        elif subcommand == 'инфа':
            meme_name = args.get_str(2, '').lower()
            if meme_name != '':
                # Подгружаем удаляемый мем
                db_result = db.find(projection={'_id': 0, f'fun.memes.{meme_name}': 1})
                extractor = ValueExtractor(db_result)
                meme_data = extractor.get(f'fun.memes.{meme_name}', None)

                if meme_data is None:
                    CustomMemes.__print_error_text(aos, f"Мема '{meme_name}' не существует.")
                else:
                    added_time = datetime.datetime.fromtimestamp(meme_data["date"] + 10800).strftime("%d.%m.%Y")    # Время из БД + часовой пояс Москвы по UTC
                    meme_owner_id = meme_data["owner_id"]
                    message = VKVariable.Multi('var', 'appeal', 'str', f'Информация о меме:\n✏Имя: {meme_name}\n🤵Владелец: ', 'var', 'ownname', 'str', f'\n📅Добавлен: {added_time}\n📂Содержимое: Вложение')
                    script = f"var o=API.users.get({{'user_ids':[{meme_owner_id}]}})[0];var ownname='@id{meme_owner_id} ('+o.first_name+' '+o.last_name+')';"

                    user_permissions = UserPermissions(db, event["object"]["message"]["from_id"])
                    if user_permissions.get('customize_memes'):
                        keyboard = KeyboardBuilder(KeyboardBuilder.INLINE_TYPE)
                        keyboard.callback_button("Удалить", ['fun_memes', event["object"]["message"]["from_id"], 2, meme_name], KeyboardBuilder.NEGATIVE_COLOR)
                        keyboard = keyboard.build()

                        aos.messages_send(message=message, attachment=meme_data["content"], script=script, keyboard=keyboard)
                    else:
                        aos.messages_send(message=message, attachment=meme_data["content"], script=script)
            else:
                CustomMemes.__print_help_message_info(aos, args)
        else:
            CustomMemes.__print_error_message_unknown_subcommand(aos, args)

    @staticmethod
    def callback_button_command(callback_object: dict):
        event = callback_object["event"]
        payload = callback_object["payload"]
        db = callback_object["db"]
        output = callback_object["output"]
        manager = callback_object["manager"]

        aos = AdvancedOutputSystem(output, event, db)

        # Проверка режима allow_memes
        chat_modes = manager.chat_modes
        if not chat_modes.get('allow_memes'):
            mode_label = ChatModes.get_label('allow_memes')
            aos.show_snackbar(text=f"⛔ Режим {mode_label} отключен.")
            return
		
        testing_user_id = payload.get_int(1, event["object"]["user_id"])
        if testing_user_id != event["object"]["user_id"]:
            aos.show_snackbar(text=DEFAULT_MESSAGES.SNACKBAR_NO_RIGHTS_TO_USE_THIS_BUTTON)
            return

        subcommand = payload.get_int(2, 1)
        if subcommand == 1:
            # Подгружаем все мемы базы данных
            db_result = db.find(projection={'_id': 0, 'fun.memes': 1})
            extractor = ValueExtractor(db_result)
            all_memes = extractor.get('fun.memes', [])

            names = list(all_memes)
            builder = PageBuilder(names, CustomMemes.SHOW_SIZE)
            number = payload.get_int(3, 1)

            try:
                page = builder(number)
                text = 'Список мемов [{}/{}]:'.format(number, builder.max_number)
                for i in page:
                    text += "\n• " + i

                keyboard = KeyboardBuilder(KeyboardBuilder.INLINE_TYPE)
                if number > 1:
                    prev_number = number - 1
                    keyboard.callback_button("{} ⬅".format(int2emoji(prev_number)), ['fun_memes', event["object"]["user_id"], 1, prev_number], KeyboardBuilder.SECONDARY_COLOR)
                if number < builder.max_number:
                    next_number = number + 1
                    keyboard.callback_button("➡ {}".format(int2emoji(next_number)), ['fun_memes', event["object"]["user_id"], 1, next_number], KeyboardBuilder.SECONDARY_COLOR)
                keyboard.new_line()
                keyboard.callback_button('Закрыть', ['bot_cancel', event["object"]["user_id"]], KeyboardBuilder.NEGATIVE_COLOR)

                aos.messages_edit(message=VKVariable.Multi('var', 'appeal', 'str', text), keyboard=keyboard.build())
            except PageBuilder.PageNumberException:
                aos.show_snackbar(text="⛔ Неверный номер страницы.")
        elif subcommand == 2:
            user_permissions = UserPermissions(db, testing_user_id)
            if user_permissions.get('customize_memes'):
                meme_name = payload.get_str(3, '').lower()
                if meme_name != '':
                    # Подгружаем удаляемый мем
                    db_result = db.find(projection={'_id': 0, f'fun.memes.{meme_name}': 1})
                    extractor = ValueExtractor(db_result)
                    meme_data = extractor.get(f'fun.memes.{meme_name}', None)

                    if meme_data is None:
                        aos.show_snackbar(text=f"⛔ Мема '{meme_name}' не существует.")
                    else:
                        db.update({"$unset": {f'fun.memes.{meme_name}': 0}})

                        # Сообщаем, что мем удален
                        aos.messages_edit(message=VKVariable.Multi('var', 'appeal', 'str', '✅Мем удален!'))
                else:
                    aos.show_snackbar(text=DEFAULT_MESSAGES.SNACKBAR_INTERNAL_ERROR)
            else:
                aos.messages_send(message=DEFAULT_MESSAGES.SNACKBAR_YOU_HAVE_NO_RIGHTS)
        else:
            aos.show_snackbar(text=DEFAULT_MESSAGES.SNACKBAR_INTERNAL_ERROR)

    def __print_error_text(aos: AdvancedOutputSystem, text: str):
        aos.messages_send(message=VKVariable.Multi('var', 'appeal', 'str', '⛔{}'.format(text)))

    def __print_help_message_add(aos: AdvancedOutputSystem, args: ArgumentParser):
        message_text = '⚠Позволяет добавлять кастомные мемы в беседу.\n\nИспользуйте с вложением:\n➡️ {} {} [название]'.format(args.get_str(0).lower(), args.get_str(1).lower())
        aos.messages_send(message=VKVariable.Multi('var', 'appeal', 'str', message_text))

    def __print_help_message_del(aos: AdvancedOutputSystem, args: ArgumentParser):
        message_text = '⚠Позволяет удалять кастомные мемы из беседы.\n\nИспользуйте:\n➡️ {} {} [название]'.format(args.get_str(0).lower(), args.get_str(1).lower())
        aos.messages_send(message=VKVariable.Multi('var', 'appeal', 'str', message_text))

    def __print_help_message_cls(aos: AdvancedOutputSystem, args: ArgumentParser, memes_count: int):
        message_text = '⚠Позволяет удалить ВСЕ кастомные мемы.\n\nИспользуйте:\n➡️ {} {} {}'.format(args.get_str(0).lower(), args.get_str(1).lower(), memes_count)
        aos.messages_send(message=VKVariable.Multi('var', 'appeal', 'str', message_text))

    def __print_help_message_info(aos: AdvancedOutputSystem, args: ArgumentParser):
        message_text = '⚠Позволяет узнать информацию о кастомном меме.\n\nИспользуйте:\n➡️ {} {} [название]'.format(args.get_str(0).lower(), args.get_str(1).lower())
        aos.messages_send(message=VKVariable.Multi('var', 'appeal', 'str', message_text))

    def __get_error_message_you_have_no_rights():
        permit_label = ManagerData.get_user_permissions_data()["customize_memes"]["label"]
        text = f"⛔ Для того, чтобы добавлять/удалять мемы необходимо иметь право {permit_label}."
        return text

    def __print_error_message_unknown_subcommand(aos: AdvancedOutputSystem, args: ArgumentParser):
        help_builder = CommandHelpBuilder('⛔Неверная субкоманда.')
        help_builder.command('{} показ', args.get_str(0).lower())
        help_builder.command('{} добав', args.get_str(0).lower())
        help_builder.command('{} удал', args.get_str(0).lower())
        help_builder.command('{} очис', args.get_str(0).lower())
        help_builder.command('{} инфа', args.get_str(0).lower())

        aos.messages_send(message=VKVariable.Multi('var', 'appeal', 'str', help_builder.build()))

class FunSeamCarving:
    IMG_MAX_SIZE = 300                                  # Максимальный размер ширины
    __queue_thread = None                               # Ссылка на поток очереди
    __job_queue = []                                    # Очередь на обработку
    __last_job_duration = None                          # Количество секунд обработки последнего задания

    @staticmethod
    def message_command(callback_object: dict):
        event = callback_object["event"]
        args = callback_object["args"]
        db = callback_object["db"]
        output = callback_object["output"]

        aos = AdvancedOutputSystem(output, event, db)

        try:
            photo = get_high_resolution_attachment_photo(event["object"]["message"]["attachments"][0])
        except IndexError:
            message_text = f'⚠Позволяет смешно "жмыхнуть" фотографию, прикрепленную к команде.\n\nИспользуйте:\n➡️ {args.get_str(0)} [фотография]'
            aos.messages_send(message=VKVariable.Multi('var', 'appeal', 'str', message_text))
            return

        if photo == None:
            message_text = f'⛔Необходимо прикрепить фотографию.\n\nИспользуйте:\n➡️ {args.get_str(0)} [фотография]'
            aos.messages_send(message=VKVariable.Multi('var', 'appeal', 'str', message_text))
            return
        
        # Подгружаем нужную картинку и преобразовываем
        img = Image.open(requests.get(photo["url"], stream=True).raw)                                       # Подгружаем картинку
        dst_size = [img.size[0], img.size[1]]															    # Список размера искаженной картинки
        if dst_size[0] > FunSeamCarving.IMG_MAX_SIZE or dst_size[1] > FunSeamCarving.IMG_MAX_SIZE:
            if dst_size[0] >= dst_size[1]:
                # Если ширина >= высота
                dst_size[1] = int(FunSeamCarving.IMG_MAX_SIZE * dst_size[1] / dst_size[0])					# Вычисляем новую высоту
                dst_size[0] = int(dst_size[0] / (dst_size[0] / FunSeamCarving.IMG_MAX_SIZE))				# Вычиляем новую ширину
            else:
                # Если ширина < высота
                dst_size[0] = int(FunSeamCarving.IMG_MAX_SIZE * dst_size[0] / dst_size[1])					# Вычисляем новую ширину
                dst_size[1] = int(dst_size[1] / (dst_size[1] / FunSeamCarving.IMG_MAX_SIZE))				# Вычиляем новую высоту
            img.thumbnail(dst_size)						                                                    # Изменяем картинку до нового размера

        photo_path = os.path.join(SYSTEM_PATHS.TMP_DIR, "{}.jpg".format(generate_random_string(10, uppercase=False)))
        img.save(photo_path, "JPEG")

        if FunSeamCarving.__last_job_duration is not None:
            duration = (len(FunSeamCarving.__job_queue) + 1) * FunSeamCarving.__last_job_duration
            duration_text = f"\n\n⏳Ожидание: ~{duration} сек."
        else:
            duration_text = ""
        message_text = f'✅Фотография добавлена в очередь.{duration_text}'
        peer_id = event["object"]["message"]["peer_id"]
        aos_res = aos.messages_send(message=VKVariable.Multi('var', 'appeal', 'str', message_text), pscript=f"return API.photos.getMessagesUploadServer({{peer_id:{peer_id}}});")

        job = {
            "aos_output": aos,
            "path": photo_path,
            "upload_link": aos_res.response["upload_url"]
        }
        FunSeamCarving.__job_queue.append(job)

    @staticmethod
    def start_queue_thread():
        if FunSeamCarving.__queue_thread is None:
            FunSeamCarving.__queue_thread = threading.Thread(target=FunSeamCarving.__queue_handler, daemon=True)
            FunSeamCarving.__queue_thread.start()

    @staticmethod
    def __queue_handler():
        while True:
            try:
                current_job = FunSeamCarving.__job_queue[0]

                start_time = time.time()                                                                # Начало вычисления продолжительности обработки
                src_img = Image.open(current_job["path"])
                src_size = src_img.size
                src_array = np.array(src_img)
                src_img.close()
                src_h, src_w, _ = src_array.shape
                dst_array = seam_carving.resize(
                    src_array, (src_w / 2, src_h / 2),
                    energy_mode='backward',                                                             # Choose from {backward, forward}
                    order='width-first',                                                                # Choose from {width-first, height-first}
                    keep_mask=None
                )

                dst_img = Image.fromarray(dst_array)
                dst_img.resize(src_size, Image.Resampling.LANCZOS).save(current_job["path"], "JPEG")
                FunSeamCarving.__last_job_duration = round(time.time() - start_time, 2)                # Конец вычисления продолжительности обработки
                threading.Thread(target=FunSeamCarving.__sender_worker, daemon=True, args=[current_job]).start()
                FunSeamCarving.__job_queue.pop(0)
            except IndexError:
                time.sleep(1)

    @staticmethod
    def __sender_worker(job: dict):
        aos = job["aos_output"]

        img_file = open(job["path"], 'rb')
        upload_result = requests.post(job["upload_link"], files={'photo': img_file}).text
        img_file.close()

        message_text = 'Жмыхнул😎'
        aos.messages_send(message=VKVariable.Multi('var', 'appeal', 'str', message_text), attachment=VKVariable.Multi("var", "photo"), script=f"var doc=API.photos.saveMessagesPhoto({upload_result})[0]; var photo=\"photo\"+doc.owner_id+\"_\"+doc.id;")
        #os.remove(job["path"])  # TODO Удаление в tmp?


class FunQuote:
    IMG_WIDTH = 300     # Ширина изображения

    # private модот для добавление слова в массив слов, с учетом ограничения длинны строки
    def __add_word_to_list(word_list, max_width, font, word):
        word_list_text_length = font.getlength(' '.join(word_list[-1]))
        word_length = font.getlength(word)
        if word_list_text_length + word_length <= max_width:
            word_list[-1].append(word)
        else:
            word_list.append([word])

    @staticmethod
    def message_command(callback_object: dict):
        event = callback_object["event"]
        args = callback_object["args"]
        db = callback_object["db"]
        output = callback_object["output"]
        vk_api = callback_object["vk_api"]

        aos = AdvancedOutputSystem(output, event, db)

        reply_message = get_reply_message_from_event(event)

        if reply_message is not None:
            from_id = reply_message["from_id"]
            if from_id > 0:
                peer_id = event["object"]["message"]["peer_id"]

                # Запрашиваем ссылку на выгрузку фотографии и получаем данные пользователя
                execute_data = vk_api.execute(f"var a=API.photos.getMessagesUploadServer({{peer_id:{peer_id}}});var b=API.users.get({{user_ids:{from_id},fields:\"photo_100\"}})[0];return [a, b];")["response"]

                # Получаем основной текст
                if reply_message["text"] != "":
                    main_text = reply_message["text"]
                else:
                    message_text = f'⛔Необходимо прикрепить сообщение, которое содержит текст.'
                    aos.messages_send(message=VKVariable.Multi('var', 'appeal', 'str', message_text))
                    return
                
                # Создаем массив строчек основного текста
                main_text = f"«{main_text}»."
                main_text_font = ImageFont.truetype(os.path.join(SYSTEM_PATHS.FONTS_DIR, "Arial-Italic.ttf"), size=16)
                main_text_list = [[]]
                for word in main_text.split():
                    word_length = main_text_font.getlength(word)
                    if word_length <= FunQuote.IMG_WIDTH - 30:
                        FunQuote.__add_word_to_list(main_text_list, FunQuote.IMG_WIDTH - 30, main_text_font, word)
                    else:
                        #curr_line_length = main_text_font.getlength(' '.join(main_text_list[-1]))
                        curr_collect_word = ""
                        for symbol in word:
                            if main_text_font.getlength(curr_collect_word + symbol + "-") >= FunQuote.IMG_WIDTH - 30:
                                FunQuote.__add_word_to_list(main_text_list, FunQuote.IMG_WIDTH - 30, main_text_font, curr_collect_word + "-")
                                curr_collect_word = ""
                                #curr_line_length = 0
                            else:
                                curr_collect_word = curr_collect_word + symbol

                        if len(curr_collect_word) > 0:
                            FunQuote.__add_word_to_list(main_text_list, FunQuote.IMG_WIDTH - 30, main_text_font, curr_collect_word)
                
                # Создаем черный квадрат
                img_height = 35 + (50 + 20 * (len(main_text_list) - 1)) + 65
                img = Image.new('RGB', (FunQuote.IMG_WIDTH, img_height), 'black')    
                idraw = ImageDraw.Draw(img)

                # Добавляем заголовок
                title_font = ImageFont.truetype(os.path.join(SYSTEM_PATHS.FONTS_DIR, "Arial-Bold.ttf"), size=18)
                title_text = "Цитаты великих людей"
                text_length = title_font.getlength(title_text)
                idraw.text(((FunQuote.IMG_WIDTH - 1) / 2 - text_length / 2, 10), title_text, font=title_font)

                # Добавляем основной текст
                curr_main_text_height = 50
                for line in main_text_list:
                    idraw.text((15, curr_main_text_height), ' '.join(line), font=main_text_font)
                    curr_main_text_height = curr_main_text_height + 20

                # Добавляем картинку и имя автора
                img_ava = Image.open(requests.get(execute_data[1]["photo_100"], stream=True).raw)
                img_mask = Image.new("L", img_ava.size, 0)
                mask_draw = ImageDraw.Draw(img_mask)
                mask_draw.ellipse((0, 0, img_mask.size[0] - 1, img_mask.size[1] - 1), fill=255)
                img_mask = img_mask.resize((50, 50))
                img_ava = img_ava.resize((50, 50))
                img.paste(img_ava, (15, curr_main_text_height + 15), img_mask)
                owner_fullname_font = ImageFont.truetype(os.path.join(SYSTEM_PATHS.FONTS_DIR, "Arial-Regular.ttf"), size=14)
                owner_fullname = "{} {}".format(execute_data[1]["first_name"], execute_data[1]["last_name"]) 
                idraw.text((75, curr_main_text_height + 31), f"© {owner_fullname}", font=owner_fullname_font)

                # Генерируем имя файла и сохраняем фотографию
                photo_path = os.path.join(SYSTEM_PATHS.TMP_DIR, "{}.jpg".format(generate_random_string(10, uppercase=False)))
                img.save(photo_path, "JPEG")

                # Загружаем фотографию и отправляем сообщение
                img_file = open(photo_path, 'rb')
                upload_result = requests.post(execute_data[0]["upload_url"], files={'photo': img_file}).text
                img_file.close()

                aos.messages_send(message=VKVariable.Multi('var', 'appeal'), attachment=VKVariable.Multi("var", "photo"), script=f"var doc=API.photos.saveMessagesPhoto({upload_result})[0]; var photo=\"photo\"+doc.owner_id+\"_\"+doc.id;")

                # TODO Удаление в tmp?
            else:
                message_text = "⛔ Автором цитаты может быть только пользователь."
                aos.messages_send(message=VKVariable.Multi('var', 'appeal', 'str', message_text))
        else:
            message_text = "⚠Создает картинку-цитату из пересланного сообщения.\n\nИспользуйте:\n➡️ {} [сообщение]".format(args.get_str(0).lower())
            aos.messages_send(message=VKVariable.Multi('var', 'appeal', 'str', message_text))