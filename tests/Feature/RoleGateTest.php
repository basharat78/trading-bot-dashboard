<?php
use App\Models\User;
use Illuminate\Support\Facades\Gate;


it('lets an admin manage the engine ',function (){
    $admin = User::factory()->create(['role' => 'admin']);

    expect(Gate::forUser($admin)->allows('manage-engine'))->toBeTrue();
});

it('does not let a viewer manage the engine', function(){
    $viewer =User::factory()->create(); //to defaluts to 'viewer'

    expect(Gate::forUser($viewer)->allows('manage-engine'))->toBeFalse();
});



