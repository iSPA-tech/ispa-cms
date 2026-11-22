<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Achievement definition providers
    |--------------------------------------------------------------------------
    |
    | Producer modules own their own achievement catalog by implementing
    | App\Contracts\Achievements\AchievementDefinitionProvider and listing
    | the class here. Cms\Achievements\Services\AchievementCatalogSynchronizer
    | resolves each class via the container and syncs the returned
    | definitions into the achievements catalog tables.
    |
    */
    'providers' => [
        \Cms\QuestionBank\Achievements\QuestionBankAchievementDefinitions::class,
    ],
];
