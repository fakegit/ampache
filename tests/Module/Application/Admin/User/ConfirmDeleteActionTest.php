<?php

/*
 * vim:set softtabstop=4 shiftwidth=4 expandtab:
 *
 * LICENSE: GNU Affero General Public License, version 3 (AGPL-3.0-or-later)
 * Copyright Ampache.org, 2001-2023
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 */

declare(strict_types=1);

namespace Ampache\Module\Application\Admin\User;

use Ampache\Config\ConfigContainerInterface;
use Ampache\Config\ConfigurationKeyEnum;
use Ampache\Module\Authorization\AccessLevelEnum;
use Ampache\Module\Authorization\GuiGatekeeperInterface;
use Ampache\Module\Util\RequestParserInterface;
use Ampache\Module\Util\UiInterface;
use Ampache\Repository\Model\ModelFactoryInterface;
use Ampache\Repository\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

class ConfirmDeleteActionTest extends TestCase
{
    use UserAdminAccessTestTrait;

    private RequestParserInterface&MockObject $requestParser;

    private UiInterface&MockObject $ui;

    private ModelFactoryInterface&MockObject $modelFactory;

    private ConfigContainerInterface&MockObject $configContainer;

    private GuiGatekeeperInterface&MockObject $gatekeeper;

    private ServerRequestInterface&MockObject $request;

    private ConfirmDeleteAction $subject;

    protected function setUp(): void
    {
        $this->requestParser   = $this->createMock(RequestParserInterface::class);
        $this->ui              = $this->createMock(UiInterface::class);
        $this->modelFactory    = $this->createMock(ModelFactoryInterface::class);
        $this->configContainer = $this->createMock(ConfigContainerInterface::class);

        $this->subject = new ConfirmDeleteAction(
            $this->requestParser,
            $this->ui,
            $this->modelFactory,
            $this->configContainer,
        );

        $this->gatekeeper = $this->createMock(GuiGatekeeperInterface::class);
        $this->request    = $this->createMock(ServerRequestInterface::class);
    }

    protected function getValidationFormName(): string
    {
        return 'delete_user';
    }

    public function testHandleDeletes(): void
    {
        $userId   = 666;
        $webPath  = 'some-path';
        $userName = 'some-name';

        $user = $this->createMock(User::class);

        $this->gatekeeper->expects(static::once())
            ->method('mayAccess')
            ->with(AccessLevelEnum::TYPE_INTERFACE, AccessLevelEnum::LEVEL_ADMIN)
            ->willReturn(true);

        $this->configContainer->expects(static::once())
            ->method('isFeatureEnabled')
            ->with(ConfigurationKeyEnum::DEMO_MODE)
            ->willReturn(false);
        $this->configContainer->expects(static::once())
            ->method('getWebPath')
            ->willReturn($webPath);

        $this->requestParser->expects(static::once())
            ->method('verifyForm')
            ->with('delete_user')
            ->willReturn(true);

        $this->request->expects(static::once())
            ->method('getQueryParams')
            ->willReturn(['user_id' => (string) $userId]);

        $this->modelFactory->expects(static::once())
            ->method('createUser')
            ->with($userId)
            ->willReturn($user);

        $user->expects(static::once())
            ->method('getUsername')
            ->willReturn($userName);
        $user->expects(static::once())
            ->method('delete')
            ->willReturn(true);

        $this->ui->expects(static::once())
            ->method('showHeader');
        $this->ui->expects(static::once())
            ->method('showConfirmation')
            ->with(
                'No Problem',
                sprintf('%s has been deleted', $userName),
                sprintf('%s/admin/users.php', $webPath)
            );
        $this->ui->expects(static::once())
            ->method('showQueryStats');
        $this->ui->expects(static::once())
            ->method('showFooter');

        static::assertNull(
            $this->subject->run($this->request, $this->gatekeeper)
        );
    }

    public function testHandleErrorsOnDeletionFailure(): void
    {
        $userId  = 666;
        $webPath = 'some-path';

        $user = $this->createMock(User::class);

        $this->gatekeeper->expects(static::once())
            ->method('mayAccess')
            ->with(AccessLevelEnum::TYPE_INTERFACE, AccessLevelEnum::LEVEL_ADMIN)
            ->willReturn(true);

        $this->configContainer->expects(static::once())
            ->method('isFeatureEnabled')
            ->with(ConfigurationKeyEnum::DEMO_MODE)
            ->willReturn(false);
        $this->configContainer->expects(static::once())
            ->method('getWebPath')
            ->willReturn($webPath);

        $this->requestParser->expects(static::once())
            ->method('verifyForm')
            ->with('delete_user')
            ->willReturn(true);

        $this->request->expects(static::once())
            ->method('getQueryParams')
            ->willReturn(['user_id' => (string) $userId]);

        $this->modelFactory->expects(static::once())
            ->method('createUser')
            ->with($userId)
            ->willReturn($user);

        $user->expects(static::once())
            ->method('delete')
            ->willReturn(false);

        $this->ui->expects(static::once())
            ->method('showHeader');
        $this->ui->expects(static::once())
            ->method('showConfirmation')
            ->with(
                'There Was a Problem',
                'You need at least one active Administrator account',
                sprintf('%s/admin/users.php', $webPath)
            );
        $this->ui->expects(static::once())
            ->method('showQueryStats');
        $this->ui->expects(static::once())
            ->method('showFooter');

        static::assertNull(
            $this->subject->run($this->request, $this->gatekeeper)
        );
    }
}
