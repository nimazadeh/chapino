<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Application;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validation\Validator;

/**
 * The development-only style guide.
 *
 * It exists so the design system is something a person can look at and the next phases can copy from
 * - and so component states (invalid input, empty list, loading) are designed before the screens that
 * need them are written, instead of being improvised later.
 *
 * Two rules this class demonstrates, both of which every later form controller follows:
 *
 *  1. **The token comes from the session, and the form must carry it.** The controller asks for the
 *     token, which means a page with a form starts a session for an anonymous visitor - a deliberate
 *     cost, paid only on pages that really submit something.
 *  2. **Validation failure is a state of the page, not an exception.** The user gets the same page
 *     back, with Persian field messages, and every value they typed is still in the fields.
 */
final class DesignSystemController
{
    public function __construct(private readonly Application $app)
    {
    }

    /** GET /design-system - the style guide. */
    public function show(Request $request): Response
    {
        $this->refuseInProduction();

        return $this->page($request, [], [], null);
    }

    /** POST /design-system - the working form demonstration. */
    public function submit(Request $request): Response
    {
        $this->refuseInProduction();

        $validator = new Validator(
            $request->only('full_name', 'mobile', 'quantity'),
            [
                'full_name' => 'required|string|min:3|max:100',
                'mobile' => 'required|iran_mobile',
                'quantity' => 'required|integer|min:1|max:10',
            ],
            [
                'full_name' => 'نام و نام خانوادگی',
                'mobile' => 'شماره موبایل',
                'quantity' => 'تعداد',
            ],
        );

        if (!$validator->isValid()) {
            // 422, because nothing was created and the client must not treat it as success.
            return $this->page($request, $validator->errors(), $request->only('full_name', 'mobile', 'quantity'), null, 422);
        }

        /** @var array<string, mixed> $clean */
        $clean = $validator->validate();

        return $this->page($request, [], [], [
            'full_name' => (string) $clean['full_name'],
            'mobile' => (string) $clean['mobile'],
            'quantity' => (string) (int) $clean['quantity'],
        ]);
    }

    /**
     * The style guide is development tooling: in production it must not exist at all.
     *
     * A 404 - not a 403 and not a redirect - because a page that says "you are not allowed" also says
     * "this page exists". An installation that is live reveals nothing about its own tooling.
     */
    private function refuseInProduction(): void
    {
        if ($this->app->config()->string('app.env', 'production') === 'production') {
            throw HttpException::notFound();
        }
    }

    /**
     * @param array<string, string> $fieldErrors
     * @param array<string, mixed> $values
     * @param array<string, string>|null $submitted
     */
    private function page(
        Request $request,
        array $fieldErrors,
        array $values,
        ?array $submitted,
        int $status = 200,
    ): Response {
        $view = $this->app->view();

        $html = $view->render('layouts/base', [
            'title' => 'سیستم طراحی',
            'base' => $request->basePath(),
            // The form needs the token; asking for it is what starts the session on this page.
            'csrfToken' => $this->app->csrf()->token(),
            'content' => $view->render('design-system', [
                'base' => $request->basePath(),
                'csrfToken' => $this->app->csrf()->token(),
                'tokenGroups' => $this->tokenGroups(),
                'fieldErrors' => $fieldErrors,
                'values' => $values,
                'submitted' => $submitted,
            ]),
        ]);

        return $status === 200 ? Response::html($html) : Response::html($html, $status);
    }

    /**
     * The tokens the page displays, read from the stylesheet rather than re-typed.
     *
     * Re-typing them would guarantee they drift: the page would keep showing a colour the interface no
     * longer uses. The test suite goes further and fails if a `var(--x)` used anywhere has no
     * definition at all.
     *
     * @return array{colour: array<string, string>, space: list<string>}
     */
    private function tokenGroups(): array
    {
        return [
            'colour' => [
                'رنگ اصلی' => '--color-brand-500',
                'پس‌زمینه' => '--color-surface',
                'زمینه‌ی تودرتو' => '--color-surface-sunken',
                'متن' => '--color-text',
                'متن کم‌رنگ' => '--color-text-muted',
                'موفق' => '--color-success-500',
                'هشدار' => '--color-warning-500',
                'خطر' => '--color-danger-500',
            ],
            'space' => [
                '--space-1',
                '--space-2',
                '--space-3',
                '--space-4',
                '--space-5',
                '--space-6',
                '--space-8',
            ],
        ];
    }
}
