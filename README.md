# e2pv
ARCHIVED repo moved to: https://codeberg.org/slash909uk/e2pv
Enecsys solar inverter reporting gateway for Domoticz/MQTT and PVoutput.org, based on Otto's work here:
https://github.com/omoerbeek/e2pv

/*
 * Copyright (c) 2015 Otto Moerbeek <otto@drijf.net>
 *
 * Permission to use, copy, modify, and distribute this software for any
 * purpose with or without fee is hereby granted, provided that the above
 * copyright notice and this permission notice appear in all copies.
 *
 * THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES
 * WITH REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF
 * MERCHANTABILITY AND FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR
 * ANY SPECIAL, DIRECT, INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES
 * WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS, WHETHER IN AN
 * ACTION OF CONTRACT, NEGLIGENCE OR OTHER TORTIOUS ACTION, ARISING OUT OF
 * OR IN CONNECTION WITH THE USE OR PERFORMANCE OF THIS SOFTWARE.
 */
 
/* Updates by Stuart Ashby <stuart@ashbysoft.com>:
 * 1/ Syslog event logging inspired by; https://gist.github.com/coderofsalvation/11325307
 * 2/ Remote alarm mod ; emits warning when panels stop generating or in error state:
 * alarm format is:
 * alarm message := code.msg
 *   code := 100|101
 *   if(code=100) msg := status.panel
 *   if(code==101) msg := activecount.panels
 *   status := [0-9]+
 *   activecount := [0-9]+
 *   panels := panel[.panels]
 *   panel := [0-9]+
 *
 * 3/ VERBOSE param to turn on/off per inverter report to stdout
 * 4/ mapping inverter ID to a panel number for easier identification on system
 * refer config.php for panels array
 * 5/ Message forwarding to chained server e.g. enecsysparts.com IGS. Defaults to 'off', toggle on/off with SIGHUP (=>upstart reload command)
 * 6/ Local generating updates pushed to domoticz via MQTT to avoid PVOutput.org delay
 * 7/ Filter top bit of State as it seems to always be set?
 * 8/ always report timestamps in UTC
 * 9/ VERBOSE report uses map_panel to ease identification
 * 10/ add voltage reporting to a simple MQTT topic to support openevse power calculation
 * 11/ add runtime debug and verbose toggles via MQTT cmds
 */

Example configuration is in config.php.example => rename to config.php before use and update details accordingly for your system!

Stu
