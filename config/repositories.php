<?php

use App\Repositories\Eloquent\ApiLogRepository;
use App\Repositories\Eloquent\BannerRepository;
use App\Repositories\Eloquent\CalendarRepository;
use App\Repositories\Eloquent\CommentRepository;
use App\Repositories\Eloquent\ContactRepository;
use App\Repositories\Eloquent\GalleryRepository;
use App\Repositories\Eloquent\ProvinceRepository;
use App\Repositories\Eloquent\SchedulerRepository;
use App\Repositories\Eloquent\TrackRepository;
use App\Repositories\Interfaces\ApiLogInterface;
use App\Repositories\Interfaces\BannerInterface;
use App\Repositories\Interfaces\CalendarInterface;
use App\Repositories\Interfaces\CommentInterface;
use App\Repositories\Interfaces\ContactInterface;
use App\Repositories\Interfaces\GalleryInterface;
use App\Repositories\Interfaces\ProvinceInterface;
use App\Repositories\Interfaces\SchedulerInterface;
use App\Repositories\Interfaces\TrackInterface;

$bindings = [
    ApiLogInterface::class => ApiLogRepository::class,
    BannerInterface::class => BannerRepository::class,
    CalendarInterface::class => CalendarRepository::class,
    CommentInterface::class => CommentRepository::class,
    ContactInterface::class => ContactRepository::class,
    GalleryInterface::class => GalleryRepository::class,
    \App\Repositories\Interfaces\PlaceInterface::class => \App\Repositories\Eloquent\PlaceRepository::class,
    ProvinceInterface::class => ProvinceRepository::class,
    SchedulerInterface::class => SchedulerRepository::class,
    TrackInterface::class => TrackRepository::class,
];

if (interface_exists(\Cms\GeoPlaces\Repositories\Interfaces\PlaceInterface::class)) {
    $bindings[\Cms\GeoPlaces\Repositories\Interfaces\PlaceInterface::class] = \Cms\GeoPlaces\Repositories\Eloquent\PlaceRepository::class;
}

return [
    'bindings' => $bindings,
];
