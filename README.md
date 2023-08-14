=============================================================
=== confirmMembership plugin
=== Version: 1.0.0
=============================================================


About
-----
This plugin contains at task, where contains the following steps
1) Send emails to useres there have not been looked ind for at specifik amound of days (the number of days are configuable in the plugin settings)
2) If the users don't login after resivein the confirm membership mail, the task will disable the user (the number of days are configuable in the plugin settings)
3) A specific number of days (configurable in the plugin settings) after   the user has been disabled, the user will bee merged 


System Requirements
-------------------
OJS 3.3

Installation
------------
* Manual installation (not recommended):
    * Copy the release source or unpack the release package into the OJS plugins/generic/confirmMembership/ folder.
    * Run `php tools/upgrade.php upgrade` from the OJS folder.
    * Go to Settings -> Website -> Plugins -> Generic Plugin -> confirm Membership Plugin and enable the plugin.
    * Configure the plugin under the confirm membership settings form