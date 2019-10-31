<?php

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

namespace SFW2\Content\Controller;

use SFW2\Routing\AbstractController;
use SFW2\Routing\Result\Content;

class Poster extends AbstractController {


    public function index(bool $all = false) {
        unset($all);
        $content = new Content('SFW2\\Content\\Poster');
        $content->assign('pathId', $this->pathId);
        return $content;
    }

    public function update(bool $all = false) {
        $entryId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if($entryId === false) {
            throw new ResolverException("invalid data given", ResolverException::INVALID_DATA_GIVEN);
        }
        return $this->modify($entryId);
    }

    /*
    public function addImage() : Content {
        $galleryId = filter_input(INPUT_POST, 'gallery', FILTER_SANITIZE_STRING);

        $folder = $this->getGalleryPath($galleryId);

        $filename = $this->addFile($folder, self::DIMENSIONS);

        $highFolder = $folder . DIRECTORY_SEPARATOR . 'high' . DIRECTORY_SEPARATOR;

        if(!is_file($folder . self::PREVIEW_FILE)) {
            $this->generatePreview($filename, self::DIMENSIONS, $highFolder, $folder);
        }

        return new Content();
    }
*/


}