Cron Plugin for Hotaru CMS
--------------------------------
Created by: shibuya246 (http://shibuya246.com)

Description
-----------
Emulate server based cron jobs by checking db on page load whether to run specified tasks.

Instructions
------------
1. Upload the "cron" folder to your plugins folder.
2. Install it from Plugin Management in Admin. 
3. Automatically, a system cron job to check daily for the latest hotaru version will be created
4. Other plugins may add cron jobs which you can check later from Admin->PluginSettings->Cron

Changelog
---------
v.0.4 2010/06/07 - shibuya246 - Maintain settings when reinstall plugin. Provide way to flush all jobs from settings page
v.0.3 2010/06/04 - shibuya246 - Change path of api request, add language file, new version check for Hotaru
v.0.2 2010/05/15 - shibuya246 - Added plugin version update job
v.0.1 2010/03/21 - shibuya246 - Released first version