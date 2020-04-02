#!/bin/sh

web_home=/home    #项目存放位置
backup='lduoj_'$(date "+%Y%m%d_%H%M%S")

# 备份整个项目，包括storage
if [ ! -d ${web_home}/lduoj_backup/${backup} ];then
  mkdir -p ${web_home}/lduoj_backup/${backup}
fi;

# project
cp -r -f -p ${web_home}/LDUOnlineJudge ${web_home}/lduoj_backup/${backup}/

# mysql
USER=`cat /etc/mysql/debian.cnf |grep user|head -1|awk '{print $3}'`
PASSWORD=`cat /etc/mysql/debian.cnf |grep password|head -1|awk '{print $3}'`
mysqldump -u${USER} -p${PASSWORD} -B lduoj > ${web_home}/lduoj_backup/${backup}/lduoj.sql

# nginx
cp -r -f -p /etc/nginx/conf.d/lduoj.conf ${web_home}/lduoj_backup/${backup}/lduoj.nginx.conf

echo -e "\nYou have successfully backuped LDU Online Judge!"
echo -e "Backup location: ${web_home}/lduoj_backup/${backup}/\n"
