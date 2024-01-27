/*
 *  Project:    sfw2-content
 *
 *  Copyright (C) 2019 Stefan Paproth <pappi-@gmx.de>
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as
 *  published by the Free Software Foundation, either version 3 of the
 *  License, or (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program. If not, see <http://www.gnu.org/licenses/agpl.txt>.
 *
 */

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

CREATE TABLE `{TABLE_PREFIX}_blog` (
    `Id` int(10) UNSIGNED NOT NULL,
    `CreationDate` date NOT NULL,
    `Title` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
    `Content` text COLLATE utf8_unicode_ci NOT NULL,
    `UserId` int(10) UNSIGNED NOT NULL,
    `PathId` int(10) UNSIGNED NOT NULL,
    `DivisionId` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

ALTER TABLE `{TABLE_PREFIX}_blog` ADD PRIMARY KEY (`Id`);
ALTER TABLE `{TABLE_PREFIX}_blog` MODIFY `Id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;
