<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Db\Db;
use App\Domain\Employee\EmployeeAccountService;
use App\Http\BadRequestException;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Security\Guard;
use App\Support\Flash;

final class EmployeeController
{
    private const SELF_SERVICE_FIELDS = [
        'first_name',
        'last_name',
        'address_text',
        'study_subjects_text',
        'study_program_text',
        'expected_graduation_date',
    ];

    private const MANAGEMENT_FIELDS = [
        'first_name',
        'last_name',
        'address_text',
        'study_subjects_text',
        'study_program_text',
        'expected_graduation_date',
        'birth_date',
        'weekly_target_minutes',
        'contract_type_key',
    ];

    private const REASON_REQUIRED_FIELDS = [
        'birth_date',
        'weekly_target_minutes',
        'contract_type_key',
    ];

    public function profile(): Response
    {
        Guard::requireRole(['employee']);

        $service = new EmployeeAccountService(Db::pdo());
        $profile = $service->getProfileForView((int) Auth::userId(), true);
        $page = max(1, (int) ($_GET['audit_page'] ?? 1));
        $limit = 10;
        $offset = ($page - 1) * $limit;
        $audit = $service->listAuditEntries((int) Auth::userId(), $limit, $offset);

        $body = renderView('profile/show', [
            'title' => 'Mein Profil - Trackly',
            'profile' => $profile,
            'errors' => [],
            'old' => [],
            'auditRows' => $audit['rows'],
            'auditPage' => $page,
            'auditHasMore' => $audit['has_more'],
        ]);

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }

    public function saveProfile(): Response
    {
        Csrf::verifyOrFail();
        Guard::requireRole(['employee']);

        $input = $this->extractFields(self::SELF_SERVICE_FIELDS);
        $errors = $this->validateProfileFields($input, false);

        $service = new EmployeeAccountService(Db::pdo());

        if (!empty($errors)) {
            $profile = $service->getProfileForView((int) Auth::userId(), true);

            $body = renderView('profile/show', [
                'title' => 'Mein Profil - Trackly',
                'profile' => $profile,
                'errors' => $errors,
                'old' => $input,
                'auditRows' => [],
                'auditPage' => 1,
                'auditHasMore' => false,
            ]);

            return new Response(422, ['Content-Type' => 'text/html; charset=utf-8'], $body);
        }

        $service->updateOwnProfile((int) Auth::userId(), $input);
        Flash::addSuccess('Profil gespeichert.');

        return new Response(303, ['Location' => '/profile'], '');
    }

    public function coordinationIndex(): Response
    {
        Guard::requireRole(['coordination', 'admin']);

        $service = new EmployeeAccountService(Db::pdo());
        $employees = $service->listEmployeeAccounts();

        $body = renderView('coordination/employees/index', [
            'title' => 'Mitarbeitende - Koordination - Trackly',
            'employees' => $employees,
        ]);

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }

    public function coordinationShow(): Response
    {
        Guard::requireRole(['coordination', 'admin']);

        $targetUserId = $this->routeId();
        $service = new EmployeeAccountService(Db::pdo());
        $profile = $service->getProfileForView($targetUserId, !Auth::hasRole('admin'));
        $activeTab = (string) ($_GET['tab'] ?? 'profile');
        if ($activeTab !== 'audit') {
            $activeTab = 'profile';
        }

        $page = max(1, (int) ($_GET['audit_page'] ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;
        $audit = $service->listAuditEntries($targetUserId, $limit, $offset);

        $body = renderView('coordination/employees/show', [
            'title' => 'Mitarbeitenden-Konto - Trackly',
            'profile' => $profile,
            'activeTab' => $activeTab,
            'profileErrors' => [],
            'profileOld' => [],
            'accountErrors' => [],
            'accountOld' => [],
            'passwordErrors' => [],
            'passwordOld' => [],
            'auditRows' => $audit['rows'],
            'auditPage' => $page,
            'auditHasMore' => $audit['has_more'],
        ]);

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }

    public function coordinationSaveProfile(): Response
    {
        Csrf::verifyOrFail();
        Guard::requireRole(['coordination', 'admin']);

        $targetUserId = $this->routeId();
        $input = $this->extractFields(self::MANAGEMENT_FIELDS);
        $reason = $this->extractReason();
        $errors = $this->validateProfileFields($input, true);
        $this->validateReasonForSensitiveChanges($input, $reason, $errors);

        $service = new EmployeeAccountService(Db::pdo());

        if (!empty($errors)) {
            $profile = $service->getProfileForView($targetUserId, !Auth::hasRole('admin'));

            $body = renderView('coordination/employees/show', [
                'title' => 'Mitarbeitenden-Konto - Trackly',
                'profile' => $profile,
                'activeTab' => 'profile',
                'profileErrors' => $errors,
                'profileOld' => array_merge($input, ['reason' => (string) ($reason ?? '')]),
                'accountErrors' => [],
                'accountOld' => [],
                'passwordErrors' => [],
                'passwordOld' => [],
                'auditRows' => [],
                'auditPage' => 1,
                'auditHasMore' => false,
            ]);

            return new Response(422, ['Content-Type' => 'text/html; charset=utf-8'], $body);
        }

        $service->updateManagedProfile((int) Auth::userId(), $targetUserId, $input, $reason, !Auth::hasRole('admin'));
        Flash::addSuccess('Mitarbeitenden-Profil gespeichert.');

        return new Response(303, ['Location' => '/coordination/employees/' . $targetUserId], '');
    }

    public function coordinationSaveAccount(): Response
    {
        Csrf::verifyOrFail();
        Guard::requireRole(['coordination', 'admin']);

        $targetUserId = $this->routeId();
        $isActive = (($_POST['is_active'] ?? '0') === '1');
        $reason = $this->extractReason();

        if (!$isActive && $reason === null) {
            $service = new EmployeeAccountService(Db::pdo());
            $profile = $service->getProfileForView($targetUserId, !Auth::hasRole('admin'));

            $body = renderView('coordination/employees/show', [
                'title' => 'Mitarbeitenden-Konto - Trackly',
                'profile' => $profile,
                'activeTab' => 'profile',
                'profileErrors' => [],
                'profileOld' => [],
                'accountErrors' => [
                    'reason' => ['Bitte Begruendung angeben (Pflicht bei Deaktivierung).'],
                ],
                'accountOld' => ['reason' => ''],
                'passwordErrors' => [],
                'passwordOld' => [],
                'auditRows' => [],
                'auditPage' => 1,
                'auditHasMore' => false,
            ]);

            return new Response(422, ['Content-Type' => 'text/html; charset=utf-8'], $body);
        }

        $service = new EmployeeAccountService(Db::pdo());
        $service->updateManagedAccountActiveState(
            (int) Auth::userId(),
            $targetUserId,
            $isActive,
            $reason,
            !Auth::hasRole('admin'),
        );

        Flash::addSuccess('Konto-Status gespeichert.');

        return new Response(303, ['Location' => '/coordination/employees/' . $targetUserId], '');
    }

    public function coordinationSetInitialPassword(): Response
    {
        Csrf::verifyOrFail();
        Guard::requireRole(['coordination', 'admin']);

        $targetUserId = $this->routeId();
        $password = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirm'] ?? '');
        $reason = $this->extractReason();

        $errors = [];

        if (strlen($password) < 8) {
            $errors['new_password'][] = 'Das Initial-Passwort muss mindestens 8 Zeichen lang sein.';
        }

        if ($password !== $confirm) {
            $errors['new_password_confirm'][] = 'Passwort und Wiederholung stimmen nicht ueberein.';
        }

        $service = new EmployeeAccountService(Db::pdo());

        if (!empty($errors)) {
            $profile = $service->getProfileForView($targetUserId, !Auth::hasRole('admin'));

            $body = renderView('coordination/employees/show', [
                'title' => 'Mitarbeitenden-Konto - Trackly',
                'profile' => $profile,
                'activeTab' => 'profile',
                'profileErrors' => [],
                'profileOld' => [],
                'accountErrors' => [],
                'accountOld' => [],
                'passwordErrors' => $errors,
                'passwordOld' => $_POST,
                'auditRows' => [],
                'auditPage' => 1,
                'auditHasMore' => false,
            ]);

            return new Response(422, ['Content-Type' => 'text/html; charset=utf-8'], $body);
        }

        $service->setManagedInitialPassword(
            (int) Auth::userId(),
            $targetUserId,
            $password,
            $reason,
            !Auth::hasRole('admin'),
        );

        Flash::addSuccess('Initial-Passwort wurde gesetzt.');

        return new Response(303, ['Location' => '/coordination/employees/' . $targetUserId], '');
    }

    private function routeId(): int
    {
        $id = (int) ($_SERVER['ROUTE_PARAMS']['id'] ?? 0);
        if ($id <= 0) {
            throw new \RuntimeException('Invalid route parameter id.');
        }

        return $id;
    }

    private function extractReason(): ?string
    {
        if (!array_key_exists('reason', $_POST)) {
            return null;
        }

        $reason = trim((string) $_POST['reason']);
        return $reason === '' ? null : $reason;
    }

    /**
     * @param string[] $fields
     * @return array<string, mixed>
     */
    private function extractFields(array $fields): array
    {
        $input = [];

        foreach ($fields as $field) {
            if (!array_key_exists($field, $_POST)) {
                continue;
            }

            $value = $_POST[$field];

            if ($field === 'weekly_target_minutes') {
                $input[$field] = trim((string) $value);
                continue;
            }

            $input[$field] = trim((string) $value);
        }

        return $input;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, list<string>>
     */
    private function validateProfileFields(array &$input, bool $allowSensitive): array
    {
        $errors = [];

        // Self-Service fields per Prompt 1.5: first/last name required + max 100
        $this->validateTextRequired($input, 'first_name', 100, $errors);
        $this->validateTextRequired($input, 'last_name', 100, $errors);
        // Optional fields with specific maxima per requirements
        $this->validateText($input, 'address_text', 500, $errors);
        $this->validateText($input, 'study_subjects_text', 200, $errors);
        $this->validateText($input, 'study_program_text', 200, $errors);
        $this->validateGraduationDate($input, 'expected_graduation_date', $errors);

        if ($allowSensitive) {
            $this->validateDate($input, 'birth_date', $errors);
            $this->validateText($input, 'contract_type_key', 100, $errors);

            if (array_key_exists('weekly_target_minutes', $input)) {
                $raw = (string) $input['weekly_target_minutes'];

                if ($raw === '') {
                    $input['weekly_target_minutes'] = null;
                } elseif (!preg_match('/^\d+$/', $raw)) {
                    $errors['weekly_target_minutes'][] = 'Wochenziel muss eine ganze Zahl sein.';
                } else {
                    $value = (int) $raw;
                    if ($value < 0 || $value > 10080) {
                        $errors['weekly_target_minutes'][] = 'Wochenziel muss zwischen 0 und 10080 liegen.';
                    } else {
                        $input['weekly_target_minutes'] = $value;
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     */
    private function validateReasonForSensitiveChanges(array $input, ?string $reason, array &$errors): void
    {
        foreach (self::REASON_REQUIRED_FIELDS as $field) {
            if (array_key_exists($field, $input) && $reason === null) {
                $errors['reason'][] = 'Bitte Begruendung angeben (Pflicht bei sensiblen Feldern).';
                return;
            }
        }
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     */
    private function validateText(array &$input, string $field, int $maxLen, array &$errors): void
    {
        if (!array_key_exists($field, $input)) {
            return;
        }

        $value = trim((string) $input[$field]);

        if ($value === '') {
            $input[$field] = null;
            return;
        }

        if (mb_strlen($value) > $maxLen) {
            $errors[$field][] = 'Dieses Feld darf maximal ' . $maxLen . ' Zeichen lang sein.';
            return;
        }

        $input[$field] = $value;
    }

    /**
     * Validate required text field (must not be empty, max length check).
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     */
    private function validateTextRequired(array &$input, string $field, int $maxLen, array &$errors): void
    {
        if (!array_key_exists($field, $input)) {
            $errors[$field][] = 'Dieses Feld ist erforderlich.';
            return;
        }

        $value = trim((string) $input[$field]);

        if ($value === '') {
            $errors[$field][] = 'Dieses Feld ist erforderlich.';
            return;
        }

        if (mb_strlen($value) > $maxLen) {
            $errors[$field][] = 'Dieses Feld darf maximal ' . $maxLen . ' Zeichen lang sein.';
            return;
        }

        $input[$field] = $value;
    }

    /**
     * Validate graduation date: optional, valid DATE, >= 2000-01-01, <= today+15 years.
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     */
    private function validateGraduationDate(array &$input, string $field, array &$errors): void
    {
        if (!array_key_exists($field, $input)) {
            return;
        }

        $value = trim((string) $input[$field]);

        if ($value === '') {
            $input[$field] = null;
            return;
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $errors[$field][] = 'Bitte ein gueltiges Datum im Format JJJJ-MM-TT angeben.';
            return;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        if (!checkdate($month, $day, $year)) {
            $errors[$field][] = 'Bitte ein gueltiges Datum angeben.';
            return;
        }

        if ($year < 2000) {
            $errors[$field][] = 'Voraussichtlicher Abschluss darf nicht vor 2000 liegen.';
            return;
        }

        $maxYear = (int) (new \DateTimeImmutable())->modify('+15 years')->format('Y');
        if ($year > $maxYear) {
            $errors[$field][] = 'Voraussichtlicher Abschluss darf nicht mehr als 15 Jahre in der Zukunft liegen.';
            return;
        }

        $input[$field] = $value;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     */
    private function validateDate(array &$input, string $field, array &$errors): void
    {
        if (!array_key_exists($field, $input)) {
            return;
        }

        $value = trim((string) $input[$field]);

        if ($value === '') {
            $input[$field] = null;
            return;
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $errors[$field][] = 'Bitte ein gueltiges Datum im Format JJJJ-MM-TT angeben.';
            return;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        if (!checkdate($month, $day, $year)) {
            $errors[$field][] = 'Bitte ein gueltiges Datum angeben.';
            return;
        }

        $input[$field] = $value;
    }
}
