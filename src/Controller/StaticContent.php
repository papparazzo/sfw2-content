<?php

/**
 *  SFW2 - SimpleFrameWork
 *
 *  Copyright (C) 2017  Stefan Paproth
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

use SFW2\Controllers\Widget\Obfuscator\EMail;

use SFW2\Core\Database;
use SFW2\Core\Config;

class StaticContent extends AbstractController
{
    protected Config $config;
    protected string $template;
    protected array $templateData;
    protected string $title;

    public function __construct(Config $config, string $template, array $templateData = [], string $title = '')
    {
        $this->template = $template;
        $this->database = $database;
        $this->config = $config;
        $this->title = $title;
        $this->templateData = $templateData;
    }

    public function index(Request $request, ResponseEngine $responseEngine): Response
    {
        return $responseEngine->render(request: $request, template: $this->template);
        /*
        $email = $this->config->getVal('project', 'eMailWebMaster');
        $content = new Content($this->template);
        $content->assign('chairman', $this->getChairman());
        $content->assign('mailaddr', (string)(new EMail($email, $email)));
        $content->assign('title', $this->title);
        $content->assign('pathId', $this->pathId);
        $content->assignArray($this->templateData);
        */
    }
}
