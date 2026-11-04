<?php

use App\Mail\OtpCodeMail;

it('shows the code and its lifetime in both the HTML and text parts', function () {
    $mail = new OtpCodeMail('042917');

    $mail->assertHasSubject("your verification code for it's totally possible")
        ->assertSeeInHtml('042917')
        ->assertSeeInHtml('This code expires in 10 minutes.')
        ->assertSeeInText('042917')
        ->assertSeeInText('This code expires in 10 minutes.');
});

it('sends from the configured itstotallypossible.life address', function () {
    $mail = new OtpCodeMail('042917');

    expect(config('mail.from.address'))->toEndWith('@itstotallypossible.life')
        ->and($mail->render())->not->toContain('numinous');
});
