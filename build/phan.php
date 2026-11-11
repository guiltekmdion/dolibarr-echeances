<?php
/* Copyright (C) 2026		Maxime DION				<m.dion@guiltek.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * Phan of the module with the setup of Dolibarr: run from the root of a Dolibarr tree where the module is copied as htdocs/installments
 * (the setup of Dolibarr skips htdocs/custom), with the name of the module added to the names known by isModEnabled().
 */
$config = require getcwd().'/dev/tools/phan/config.php';
$config['ParamMatchRegexPlugin']['/^isModEnabled$/'][1] = str_replace('(?:', '(?:installments|', $config['ParamMatchRegexPlugin']['/^isModEnabled$/'][1]);

return $config;
