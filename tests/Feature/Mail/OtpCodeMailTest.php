<?php

use App\Enums\CodeAudience;
use App\Mail\OtpCodeMail;

it('shows the code and its lifetime in both the HTML and text parts', function () {
    $mail = new OtpCodeMail('042917');

    $mail->assertHasSubject("your verification code for it's totally possible")
        ->assertSeeInHtml('042917')
        ->assertSeeInHtml('This code expires in 10 minutes.')
        ->assertSeeInText('042917')
        ->assertSeeInText('This code expires in 10 minutes.');
});

it('says plainly that a deletion code deletes the account, and to ignore it otherwise', function () {
    $mail = new OtpCodeMail('042917', CodeAudience::AccountDeletion);

    $mail->assertHasSubject("your code to delete your it's totally possible account")
        ->assertSeeInHtml('042917')
        ->assertSeeInHtml('Here is your code to delete your account:')
        ->assertSeeInHtml('Entering this code deletes your account and everything in it. This cannot be undone.')
        ->assertSeeInHtml('If you did not ask to delete your account, ignore this email. Your account stays as it is.')
        ->assertDontSeeInHtml('Here is your verification code:')
        ->assertSeeInText("Your code to delete your it's totally possible account is:")
        ->assertSeeInText('If you did not ask to delete your account, ignore this email.');
});

it('sends from the configured itstotallypossible.life address', function () {
    $mail = new OtpCodeMail('042917');

    expect(config('mail.from.address'))->toEndWith('@itstotallypossible.life')
        ->and($mail->render())->not->toContain('numinous');
});
