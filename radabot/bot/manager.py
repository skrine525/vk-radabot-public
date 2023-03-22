from radabot.core.io import AdvancedOutputSystem, ChatEventManager
from radabot.core.manager import UserPermissions
from radabot.core.system import ManagerData, PageBuilder, SelectedUserParser, ArgumentParser, CommandHelpBuilder, get_reply_message_from_event, int2emoji
from radabot.core.vk import KeyboardBuilder, VKVariable
from radabot.core.bot import DEFAULT_MESSAGES


def initcmd(manager: ChatEventManager):
    manager.add_message_command('!права', PermissionCommand.message_command)

    manager.add_callback_button_command('manager_permits', PermissionCommand.callback_button_command)


# Команда !права
class PermissionCommand:
    @staticmethod
    def message_command(callback_object: dict):
        event = callback_object["event"]
        args = callback_object["args"]
        db = callback_object["db"]
        output = callback_object["output"]

        aos = AdvancedOutputSystem(output, event, db)

        permissions_data = ManagerData.get_user_permissions_data()

        subcommand = args.get_str(1, '').lower()
        if subcommand == 'показ':
            member_parser = SelectedUserParser()
            member_parser.set_reply_message(get_reply_message_from_event(event))
            member_parser.set_argument_parser(args, 2)
            member_id = member_parser.member_id()

            if member_id == 0:
                permits_text = "Ваши права:"
                no_permits_text = "❗У вас нет прав."
                member_id = event["object"]["message"]["from_id"]
            else:
                permits_text = "Права @id{} (пользователя):".format(member_id)
                no_permits_text = "❗У @id{} (пользователя) нет прав.".format(member_id)

            user_permissions = UserPermissions(db, member_id)
            permission_list = user_permissions.get_all()
            true_permission_count = 0
            for k, v in permission_list.items():
                if v:
                    label = permissions_data[k]['label']
                    permits_text += "\n• {}".format(label)
                    true_permission_count += 1

            if true_permission_count > 0:
                message = VKVariable.Multi('var', 'appeal', 'str', permits_text)
                aos.messages_send(message=message)
            else:
                message = VKVariable.Multi('var', 'appeal', 'str', no_permits_text)
                aos.messages_send(message=message)
        elif subcommand == 'упр':
            member_parser = SelectedUserParser()
            member_parser.set_reply_message(get_reply_message_from_event(event))
            member_parser.set_argument_parser(args, 2)
            member_id = member_parser.member_id()

            user_permissions = UserPermissions(db, event["object"]["message"]["from_id"])
            if user_permissions.get('set_permits'):
                if member_id > 0:
                    # Просчитываем права, которыми может управлять пользователь
                    can_manage_list = []
                    for k, v in user_permissions.get_all().items():
                        if not permissions_data[k]['hidden'] and v:
                            can_manage_list.append(k)
                    
                    # Удаляем set_permits из списка управляемых прав, если пользователь не является владельцем
                    if event["object"]["message"]["from_id"] != db.owner_id:
                        can_manage_list.remove('set_permits')

                    # Ошибки
                    if len(can_manage_list) == 0:
                        message_text = '⛔У вас нет прав, которыми вы можете управлять.'
                        message = VKVariable.Multi('var', 'appeal', 'str', message_text)
                        aos.messages_send(message=message)
                        return
                    elif member_id == event["object"]["message"]["from_id"]:
                        message_text = '⛔Нельзя управлять своими правами.'
                        message = VKVariable.Multi('var', 'appeal', 'str', message_text)
                        aos.messages_send(message=message)
                        return
                    elif member_id == db.owner_id:
                        message_text = '⛔Нельзя управлять правами владельца беседы.'
                        message = VKVariable.Multi('var', 'appeal', 'str', message_text)
                        aos.messages_send(message=message)
                        return
                    
                    if args.count > 2:
                        member_permissions = UserPermissions(db, member_id)

                        message_text = 'Права @id{} (пользователя):'.format(member_id)
                        for index in range(2, min(args.count, 12)):
                            permission_name = args.get_str(index, '').lower()
                            try:
                                permission_state = member_permissions.get(permission_name)
                                permission_label = permissions_data[permission_name]['label']
                                member_permissions.set(permission_name, not permission_state)

                                if permission_name in can_manage_list:
                                    if permission_state:
                                        message_text += '\n⛔ {}'.format(permission_label)
                                    else:
                                        message_text += '\n✅ {}'.format(permission_label)
                                else:
                                    message_text += '\n🚫 {}'.format(permission_label)
                            except UserPermissions.UnknownPermissionException:
                                message_text += '\n❓ {}'.format(permission_name)

                        message_text += '\n\nОбозначения:\n✅ - Право выдано\n⛔ - Право отозвано\n🚫 - Запрещено управлять\n❓ - Неизвестное право'

                        member_permissions.commit()
                        message = VKVariable.Multi('var', 'appeal', 'str', message_text)
                        aos.messages_send(message=message)
                    else:
                        permits_text = "Пусто"
                        if len(can_manage_list) > 0:
                            permits_text = ', '.join(can_manage_list)
                        message = VKVariable.Multi('var', 'appeal', 'str', '⛔Укажите права (не больше 10 штук).\n\nПрава, которыми вы можете управлять: {}.'.format(permits_text))
                        keyboard = KeyboardBuilder(KeyboardBuilder.INLINE_TYPE)
                        keyboard.callback_button('Управлять правами', ['manager_permits', event["object"]["message"]["from_id"], 1, member_id], KeyboardBuilder.PRIMARY_COLOR)
                        aos.messages_send(message=message, keyboard=keyboard.build())
                else:
                    message_text = PermissionCommand.__get_error_message_select_user(args)
                    message = VKVariable.Multi('var', 'appeal', 'str', message_text)
                    aos.messages_send(message=message)
            else:
                message = VKVariable.Multi('var', 'appeal', 'str', DEFAULT_MESSAGES.MESSAGE_YOU_HAVE_NO_RIGHTS)
                aos.messages_send(message=message)
        elif subcommand == 'инфа':
            permission_name = args.get_str(2, '').lower()

            if permission_name == '':
                message_text = 'Список прав:'
                for i in permissions_data:
                    # Не отображаем скрытые права
                    if not permissions_data[i]['hidden']:
                        message_text += '\n• ' + i
                message_text += "\n\nПодробная информация:\n➡️ !права инфа [право]"

                keyboard = KeyboardBuilder(KeyboardBuilder.INLINE_TYPE)
                keyboard.callback_button('Информация', ['manager_permits', event["object"]["message"]["from_id"], 2], KeyboardBuilder.PRIMARY_COLOR)

                message = VKVariable.Multi('var', 'appeal', 'str', message_text)
                aos.messages_send(message=message, keyboard=keyboard.build())
            else:
                try:
                    permission_data = permissions_data[permission_name]
                    message_text = "Информация:\n🆔Право: {}\n✏Название: {}\n📝Описание: {}.".format(permission_name, permission_data['label'], permission_data['desc'])
                    message = VKVariable.Multi('var', 'appeal', 'str', message_text)
                    aos.messages_send(message=message)
                except KeyError:
                    permits_text = '\n\nСписок прав:'
                    for i in permissions_data:
                        permits_text += '\n• ' + i
                    hint = '\n\nПодробная информация:\n➡️ !права инфа [право]'
                    message = VKVariable.Multi('var', 'appeal', 'str', "⛔Права '{}' не существует.{}{}".format(permission_name, permits_text, hint))
                    aos.messages_send(message=message)
        else:
            message_text = PermissionCommand.__get_error_message_unknown_subcommand(args)
            message = VKVariable.Multi('var', 'appeal', 'str', message_text)
            aos.messages_send(message=message)

    @staticmethod
    def callback_button_command(callback_object: dict):
        event = callback_object["event"]
        payload = callback_object["payload"]
        db = callback_object["db"]
        output = callback_object["output"]

        aos = AdvancedOutputSystem(output, event, db)

        permissions_data = ManagerData.get_user_permissions_data()

        testing_user_id = payload.get_int(1, event["object"]["user_id"])
        if testing_user_id != event["object"]["user_id"]:
            aos.show_snackbar(text=DEFAULT_MESSAGES.SNACKBAR_NO_RIGHTS_TO_USE_THIS_BUTTON)
            return

        sub1 = payload.get_int(2, 0)
        if sub1 == 1:
            user_permissions = UserPermissions(db, event["object"]["user_id"])
            if user_permissions.get('set_permits'):
                member_id = payload.get_int(3, 0)
                if member_id > 0:
                     # Просчитываем права, которыми может управлять пользователь
                    can_manage_list = []
                    for k, v in user_permissions.get_all().items():
                        if not permissions_data[k]['hidden'] and v:
                            can_manage_list.append(k)

                    # Удаляем set_permits из списка управляемых прав, если пользователь не является владельцем
                    if event["object"]["user_id"] != db.owner_id:
                        can_manage_list.remove('set_permits')

                    if len(can_manage_list) == 0:
                        aos.show_snackbar(text='⛔ У вас нет прав, которыми вы можете управлять.')
                        return
                    elif member_id == event["object"]["user_id"]:
                        aos.show_snackbar(text='⛔ Нельзя управлять своими правами.')
                        return
                    elif member_id == db.owner_id:
                        aos.show_snackbar(text='⛔ Нельзя управлять правами владельца беседы.')
                        return

                    member_permissions = UserPermissions(db, member_id)
                    page_number = payload.get_int(4, 1)
                    page_builder = PageBuilder(can_manage_list, 9)

                    can_commit = False
                    change_permission_name = payload.get_str(5, '')
                    if change_permission_name != '':
                        if change_permission_name in can_manage_list:
                            try:
                                new_state = not member_permissions.get(change_permission_name)
                                member_permissions.set(change_permission_name, new_state)
                                can_commit = True
                            except UserPermissions.UnknownPermissionException:
                                aos.show_snackbar(text='⛔ Неизвестное право.')
                                return
                        else:
                            aos.show_snackbar(text='⛔ Вы не можете управлять этой прав.')
                            return

                    try:
                        page = page_builder(page_number)

                        message_text = 'Права @id{} (пользователя):'.format(member_id)
                        keyboard = KeyboardBuilder(KeyboardBuilder.INLINE_TYPE)
                        keyboard.size(3)
                        for i in range(0, len(page)):
                            name = page[i]
                            state = member_permissions.get(name)
                            label = int2emoji(i+1)
                            color = KeyboardBuilder.POSITIVE_COLOR if state else KeyboardBuilder.NEGATIVE_COLOR
                            message_text += "\n{} {}".format(label, permissions_data[name]['label'])
                            keyboard.callback_button(label, ['manager_permits', testing_user_id, 1, member_id, page_number, name], color)
                        keyboard.reset_size(width=True)

                        keyboard.new_line()
                        if page_number > 1:
                            prev_number = page_number - 1
                            keyboard.callback_button("{} ⬅".format(int2emoji(prev_number)), ['manager_permits', testing_user_id, 1, member_id, prev_number], KeyboardBuilder.SECONDARY_COLOR)
                        if page_number < page_builder.max_number:
                            next_number = page_number + 1
                            keyboard.callback_button("➡ {}".format(int2emoji(next_number)), ['manager_permits', testing_user_id, 1, member_id, next_number], KeyboardBuilder.SECONDARY_COLOR)
                        
                        keyboard.new_line()
                        keyboard.callback_button('Закрыть', ['bot_cancel', testing_user_id], KeyboardBuilder.NEGATIVE_COLOR)

                        message = VKVariable.Multi('var', 'appeal', 'str', message_text)
                        aos.messages_edit(message=message, keyboard=keyboard.build())
                        if can_commit:
                            member_permissions.commit()
                    except PageBuilder.PageNumberException:
                        aos.show_snackbar(text='⛔ Ошибка номера списка.')
                else:
                    aos.show_snackbar(text='⛔ Неверный параметр пользователя.')
            else:
                aos.show_snackbar(text=DEFAULT_MESSAGES.SNACKBAR_YOU_HAVE_NO_RIGHTS)
        elif sub1 == 2:
            page_number = payload.get_int(3, 1)
            permission_name = payload.get_str(4, '')
            if permission_name == '':
                # Создаем список из нескрытых прав
                permission_names = []
                for i in permissions_data:
                    if not permissions_data[i]['hidden']:
                        permission_names.append(i)
                # Создаем билдер списка
                page_builder = PageBuilder(permission_names, 9)
                try:
                    page = page_builder(page_number)

                    message_text = 'Список прав:'
                    keyboard = KeyboardBuilder(KeyboardBuilder.INLINE_TYPE)
                    keyboard.size(3)
                    for i in range(0, len(page)):
                        name = page[i]
                        label = int2emoji(i+1)
                        message_text += '\n{} {}'.format(label, permissions_data[name]['label'])
                        keyboard.callback_button(label, ['manager_permits', testing_user_id, 2, page_number, name], KeyboardBuilder.POSITIVE_COLOR)
                    keyboard.reset_size(width=True)

                    keyboard.new_line()
                    if page_number > 1:
                        prev_number = page_number - 1
                        keyboard.callback_button("{} ⬅".format(int2emoji(prev_number)), ['manager_permits', testing_user_id, 2, prev_number], KeyboardBuilder.SECONDARY_COLOR)
                    if page_number < page_builder.max_number:
                        next_number = page_number + 1
                        keyboard.callback_button("➡ {}".format(int2emoji(next_number)), ['manager_permits', testing_user_id, 2, next_number], KeyboardBuilder.SECONDARY_COLOR)

                    keyboard.new_line()
                    keyboard.callback_button('Закрыть', ['bot_cancel', testing_user_id], KeyboardBuilder.NEGATIVE_COLOR)

                    message = VKVariable.Multi('var', 'appeal', 'str', message_text)
                    aos.messages_edit(message=message, keyboard=keyboard.build())
                except PageBuilder.PageNumberException:
                    aos.show_snackbar(text='⛔ Ошибка номера списка.')
            else:
                try:
                    permission_data = permissions_data[permission_name]
                    message_text = "Информация:\n🆔Право: {}\n✏Название: {}\n📝Описание: {}.".format(permission_name, permission_data['label'], permission_data['desc'])
                    keyboard = KeyboardBuilder(KeyboardBuilder.INLINE_TYPE)
                    keyboard.callback_button('⬅ Назад', ['manager_permits', testing_user_id, 2, page_number], KeyboardBuilder.PRIMARY_COLOR)
                    keyboard.new_line()
                    keyboard.callback_button('Закрыть', ['bot_cancel', testing_user_id], KeyboardBuilder.NEGATIVE_COLOR)
                    message = VKVariable.Multi('var', 'appeal', 'str', message_text)
                    aos.messages_edit(message=message, keyboard=keyboard.build())
                except KeyError:
                    aos.show_snackbar(text='⛔ Неизвестное право.')
        else:
            aos.show_snackbar(text=DEFAULT_MESSAGES.SNACKBAR_INTERNAL_ERROR)

    @staticmethod
    def __get_error_message_select_user(args: ArgumentParser):
        first_permission = list(ManagerData.get_user_permissions_data())[0]
        help_builder = CommandHelpBuilder('⛔Укажите пользователя.')
        help_builder.command('{} {} [id] [название]', args.get_str(0).lower(), args.get_str(1).lower())
        help_builder.command('{} {} [пересл. сообщение] [название]', args.get_str(0).lower(), args.get_str(1).lower())
        help_builder.command('{} {} [упоминание] [название]', args.get_str(0).lower(), args.get_str(1).lower())
        help_builder.example('{} {} @durov {}', args.get_str(0).lower(), args.get_str(1).lower(), first_permission)

        return help_builder.build()

    @staticmethod
    def __get_error_message_unknown_subcommand(args: ArgumentParser):
        help_builder = CommandHelpBuilder('⛔Неверная субкоманда.')
        help_builder.command('{} показ', args.get_str(0).lower())
        help_builder.command('{} инфа', args.get_str(0).lower())
        help_builder.command('{} упр', args.get_str(0).lower())

        return help_builder.build()
