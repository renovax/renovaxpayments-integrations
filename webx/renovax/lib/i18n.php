<?php
declare(strict_types=1);

/**
 * Tiny i18n helper. 6 idiomas obligatorios per project memory.
 *
 * Detection order:
 *   1. ?lang=xx in query string (also persists to cookie)
 *   2. cookie `renovax_lang`
 *   3. Accept-Language header
 *   4. fallback: 'en'
 */

const RX_LANGS = ['en', 'es', 'fr', 'pt', 'ru', 'ar'];

const RX_DICT = [

    'en' => [
        'page_title'        => 'Top up your balance — RENOVAX Payments',
        'recharge'          => 'Top up your balance',
        'subtitle'          => 'Pay with crypto, card or PayPal via RENOVAX Payments.',
        'user_label'        => 'Username or email',
        'user_placeholder'  => 'Enter your username or email',
        'amount_label'      => 'Amount',
        'amount_placeholder'=> 'Amount',
        'pay_button'        => 'Pay with RENOVAX Payments',
        'powered_by'        => 'Powered by RENOVAX Payments',
        'err_user_or_amount'=> 'Invalid user or amount.',
        'err_csrf'          => 'Session expired — please reload and try again.',
        'err_create'        => 'Could not create the payment. Please try again later.',
        'err_too_many'      => 'Too many pending top-ups. Wait for one to expire and try again.',
        'err_rate_limit'    => 'Too many attempts. Please wait a few minutes.',
        'success_title'     => '✓ Payment received',
        'success_body'      => 'Your balance will update in seconds.',
        'cancel_title'      => 'Payment cancelled',
        'cancel_body'       => 'No funds were charged. You can try again.',
        'try_again'         => 'Try again',
        'rtl'               => false,
    ],

    'es' => [
        'page_title'        => 'Recargar saldo — RENOVAX Payments',
        'recharge'          => 'Recargar saldo',
        'subtitle'          => 'Paga con cripto, tarjeta o PayPal vía RENOVAX Payments.',
        'user_label'        => 'Usuario o correo',
        'user_placeholder'  => 'Introduce tu usuario o correo',
        'amount_label'      => 'Monto',
        'amount_placeholder'=> 'Monto',
        'pay_button'        => 'Pagar con RENOVAX Payments',
        'powered_by'        => 'Procesado por RENOVAX Payments',
        'err_user_or_amount'=> 'Usuario o monto inválido.',
        'err_csrf'          => 'Sesión expirada — recarga la página e inténtalo de nuevo.',
        'err_create'        => 'No se pudo crear el pago. Inténtalo de nuevo más tarde.',
        'err_too_many'      => 'Demasiadas recargas pendientes. Espera a que expire alguna.',
        'err_rate_limit'    => 'Demasiados intentos. Espera unos minutos.',
        'success_title'     => '✓ Pago recibido',
        'success_body'      => 'Tu saldo se actualizará en segundos.',
        'cancel_title'      => 'Pago cancelado',
        'cancel_body'       => 'No se cobró nada. Puedes intentarlo de nuevo.',
        'try_again'         => 'Intentar de nuevo',
        'rtl'               => false,
    ],

    'fr' => [
        'page_title'        => 'Recharger le solde — RENOVAX Payments',
        'recharge'          => 'Recharger le solde',
        'subtitle'          => 'Payez en crypto, par carte ou via PayPal avec RENOVAX Payments.',
        'user_label'        => 'Identifiant ou e-mail',
        'user_placeholder'  => 'Saisissez votre identifiant ou e-mail',
        'amount_label'      => 'Montant',
        'amount_placeholder'=> 'Montant',
        'pay_button'        => 'Payer avec RENOVAX Payments',
        'powered_by'        => 'Propulsé par RENOVAX Payments',
        'err_user_or_amount'=> 'Utilisateur ou montant invalide.',
        'err_csrf'          => 'Session expirée — rechargez la page et réessayez.',
        'err_create'        => 'Impossible de créer le paiement. Réessayez plus tard.',
        'err_too_many'      => 'Trop de recharges en attente. Attendez qu\'une expire.',
        'err_rate_limit'    => 'Trop de tentatives. Patientez quelques minutes.',
        'success_title'     => '✓ Paiement reçu',
        'success_body'      => 'Votre solde sera mis à jour en quelques secondes.',
        'cancel_title'      => 'Paiement annulé',
        'cancel_body'       => 'Aucun montant n\'a été débité. Vous pouvez réessayer.',
        'try_again'         => 'Réessayer',
        'rtl'               => false,
    ],

    'pt' => [
        'page_title'        => 'Recarregar saldo — RENOVAX Payments',
        'recharge'          => 'Recarregar saldo',
        'subtitle'          => 'Pague com cripto, cartão ou PayPal via RENOVAX Payments.',
        'user_label'        => 'Usuário ou e-mail',
        'user_placeholder'  => 'Digite seu usuário ou e-mail',
        'amount_label'      => 'Valor',
        'amount_placeholder'=> 'Valor',
        'pay_button'        => 'Pagar com RENOVAX Payments',
        'powered_by'        => 'Processado por RENOVAX Payments',
        'err_user_or_amount'=> 'Usuário ou valor inválido.',
        'err_csrf'          => 'Sessão expirada — recarregue a página e tente novamente.',
        'err_create'        => 'Não foi possível criar o pagamento. Tente mais tarde.',
        'err_too_many'      => 'Muitas recargas pendentes. Aguarde uma expirar.',
        'err_rate_limit'    => 'Muitas tentativas. Aguarde alguns minutos.',
        'success_title'     => '✓ Pagamento recebido',
        'success_body'      => 'Seu saldo será atualizado em segundos.',
        'cancel_title'      => 'Pagamento cancelado',
        'cancel_body'       => 'Nada foi cobrado. Você pode tentar novamente.',
        'try_again'         => 'Tentar novamente',
        'rtl'               => false,
    ],

    'ru' => [
        'page_title'        => 'Пополнить баланс — RENOVAX Payments',
        'recharge'          => 'Пополнить баланс',
        'subtitle'          => 'Оплатите криптовалютой, картой или через PayPal с RENOVAX Payments.',
        'user_label'        => 'Логин или e-mail',
        'user_placeholder'  => 'Введите логин или e-mail',
        'amount_label'      => 'Сумма',
        'amount_placeholder'=> 'Сумма',
        'pay_button'        => 'Оплатить через RENOVAX Payments',
        'powered_by'        => 'Обработано RENOVAX Payments',
        'err_user_or_amount'=> 'Неверный пользователь или сумма.',
        'err_csrf'          => 'Сессия истекла — перезагрузите страницу и попробуйте снова.',
        'err_create'        => 'Не удалось создать платёж. Попробуйте позже.',
        'err_too_many'      => 'Слишком много ожидающих пополнений. Дождитесь истечения.',
        'err_rate_limit'    => 'Слишком много попыток. Подождите несколько минут.',
        'success_title'     => '✓ Платёж получен',
        'success_body'      => 'Ваш баланс будет обновлён через несколько секунд.',
        'cancel_title'      => 'Платёж отменён',
        'cancel_body'       => 'Ничего не списано. Можете попробовать снова.',
        'try_again'         => 'Попробовать снова',
        'rtl'               => false,
    ],

    'ar' => [
        'page_title'        => 'شحن الرصيد — RENOVAX Payments',
        'recharge'          => 'شحن الرصيد',
        'subtitle'          => 'ادفع بالعملات الرقمية أو البطاقة أو PayPal عبر RENOVAX Payments.',
        'user_label'        => 'اسم المستخدم أو البريد الإلكتروني',
        'user_placeholder'  => 'أدخل اسم المستخدم أو البريد الإلكتروني',
        'amount_label'      => 'المبلغ',
        'amount_placeholder'=> 'المبلغ',
        'pay_button'        => 'ادفع عبر RENOVAX Payments',
        'powered_by'        => 'مدعوم من RENOVAX Payments',
        'err_user_or_amount'=> 'المستخدم أو المبلغ غير صالح.',
        'err_csrf'          => 'انتهت الجلسة — أعد تحميل الصفحة وحاول مرة أخرى.',
        'err_create'        => 'تعذّر إنشاء الدفع. يرجى المحاولة لاحقاً.',
        'err_too_many'      => 'هناك عدد كبير من عمليات الشحن المعلقة. انتظر انتهاء صلاحيتها.',
        'err_rate_limit'    => 'محاولات كثيرة جداً. انتظر بضع دقائق.',
        'success_title'     => '✓ تم استلام الدفع',
        'success_body'      => 'سيتم تحديث رصيدك خلال ثوانٍ.',
        'cancel_title'      => 'تم إلغاء الدفع',
        'cancel_body'       => 'لم يتم خصم أي مبلغ. يمكنك المحاولة مرة أخرى.',
        'try_again'         => 'حاول مرة أخرى',
        'rtl'               => true,
    ],
];

function rx_lang(): string
{
    static $lang = null;
    if ($lang !== null) {
        return $lang;
    }

    $candidate = '';
    if (!empty($_GET['lang']) && in_array($_GET['lang'], RX_LANGS, true)) {
        $candidate = $_GET['lang'];
        setcookie('renovax_lang', $candidate, time() + 30 * 86400, '/');
    } elseif (!empty($_COOKIE['renovax_lang']) && in_array($_COOKIE['renovax_lang'], RX_LANGS, true)) {
        $candidate = $_COOKIE['renovax_lang'];
    } elseif (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        $accept = strtolower((string) $_SERVER['HTTP_ACCEPT_LANGUAGE']);
        foreach (RX_LANGS as $l) {
            if (strpos($accept, $l) === 0 || strpos($accept, ',' . $l) !== false || strpos($accept, ' ' . $l) !== false) {
                $candidate = $l;
                break;
            }
        }
    }

    $lang = $candidate ?: 'en';
    return $lang;
}

function t(string $key): string
{
    $lang = rx_lang();
    return RX_DICT[$lang][$key] ?? RX_DICT['en'][$key] ?? $key;
}

function rx_is_rtl(): bool
{
    return !empty(RX_DICT[rx_lang()]['rtl']);
}
