from radabot.core.io import ChatEventManager

from .manager import initcmd as initcmd_manager
from .debug import initcmd as initcmd_debug
from .basic import initcmd as initcmd_basic, initcmd_php
from .fun import initcmd as initcmd_fun
from .notification import start_notify


def handle_event(vk_api, event):
	if event["type"] in ["message_new", "message_event"]:
		manager = ChatEventManager(vk_api, event)

		initcmd_debug(manager)
		initcmd_basic(manager)
		initcmd_manager(manager)
		initcmd_fun(manager)
		initcmd_php(manager)

		manager.handle()
		manager.finish()
	elif event["type"] == "wall_post_new":
		start_notify(vk_api, event)