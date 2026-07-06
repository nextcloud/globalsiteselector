<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OC\Core\Controller;

use OC\Core\Db\LoginFlowV2;
use OC\Core\Exception\LoginFlowV2ClientForbiddenException;
use OC\Core\Exception\LoginFlowV2NotFoundException;
use OC\Core\ResponseDefinitions;
use OC\Core\Service\LoginFlowV2Service;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\NoSameSiteCookieRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\StandaloneTemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Authentication\Exceptions\InvalidTokenException;
use OCP\Defaults;
use OCP\IL10N;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use OCP\Server;
use OCP\Util;

/**
 * @psalm-import-type CoreLoginFlowV2Credentials from ResponseDefinitions
 * @psalm-import-type CoreLoginFlowV2 from ResponseDefinitions
 */
class ClientFlowLoginV2Controller extends Controller {
	public const TOKEN_NAME = 'client.flow.v2.login.token';
	public const STATE_NAME = 'client.flow.v2.state.token';

	public function __construct(string $appName, IRequest $request, private LoginFlowV2Service $loginFlowV2Service, private IURLGenerator $urlGenerator, private ISession $session, private IUserSession $userSession, private ISecureRandom $random, private Defaults $defaults, private ?string $userId, private IL10N $l10n, private IInitialState $initialState)
    {
    }

	/**
	 * Poll the login flow credentials
	 *
	 * @param string $token Token of the flow
	 * @return JSONResponse<Http::STATUS_OK, CoreLoginFlowV2Credentials, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, list<empty>, array{}>
	 *
	 * 200: Login flow credentials returned
	 * 404: Login flow not found or completed
	 */
	#[NoCSRFRequired]
    #[PublicPage]
    #[FrontpageRoute(verb: 'POST', url: '/login/v2/poll')]
    #[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT)]
    public function poll(string $token): JSONResponse
    {
    }

	#[NoCSRFRequired]
    #[PublicPage]
    #[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
    #[UseSession]
    #[FrontpageRoute(verb: 'GET', url: '/login/v2/flow/{token}')]
    public function landing(string $token, $user = '', int $direct = 0): Response
    {
    }

	#[NoCSRFRequired]
    #[PublicPage]
    #[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
    #[UseSession]
    #[FrontpageRoute(verb: 'GET', url: '/login/v2/flow')]
    public function showAuthPickerPage(string $user = '', int $direct = 0): StandaloneTemplateResponse
    {
    }

	#[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
    #[UseSession]
    #[FrontpageRoute(verb: 'GET', url: '/login/v2/grant')]
    #[NoSameSiteCookieRequired]
    public function grantPage(?string $stateToken, int $direct = 0): StandaloneTemplateResponse
    {
    }

	#[PublicPage]
    #[FrontpageRoute(verb: 'POST', url: '/login/v2/apptoken')]
    public function apptokenRedirect(?string $stateToken, string $user, string $password)
    {
    }

	#[NoAdminRequired]
    #[UseSession]
    #[PasswordConfirmationRequired(strict: false)]
    #[FrontpageRoute(verb: 'POST', url: '/login/v2/grant')]
    public function generateAppPassword(?string $stateToken): Response
    {
    }

	/**
	 * Init a login flow
	 *
	 * @return JSONResponse<Http::STATUS_OK, CoreLoginFlowV2, array{}>
	 *
	 * 200: Login flow init returned
	 */
	#[NoCSRFRequired]
    #[PublicPage]
    #[FrontpageRoute(verb: 'POST', url: '/login/v2')]
    #[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT)]
    public function init(): JSONResponse
    {
    }
}
