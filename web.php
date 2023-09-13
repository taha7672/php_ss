<?php

use App\Http\Controllers\Auth\PageAuthController;
use Illuminate\Support\Facades\Route;
use \App\Http\Controllers\HomeController;
use \App\Http\Controllers\AuthController;
use \App\Http\Controllers\CommunityCentersController;
use \App\Http\Controllers\PostController;
use Illuminate\Support\Facades\Artisan;

/*
    |--------------------------------------------------------------------------
    | Web Routes
    |--------------------------------------------------------------------------
    |
    | Here is where you can register web routes for your application. These
    | routes are loaded by the RouteServiceProvider within a group which
    | contains the "web" middleware group. Now create something great!
    |
    */


Route::post('/login', [AuthController::class, 'login'])->name('login');
Route::post('/register', [AuthController::class, 'register'])->name('register');
### LOGIN FROM PAGE ###
Route::get('/login-user', [PageAuthController::class, 'loginPage'])->name('login-page');
Route::get('/register-user', [PageAuthController::class, 'registerPage'])->name('register-page');


Route::post('/login/by/page', [PageAuthController::class, 'loginBypage'])->name('login-throught-page');
Route::post('/register/by/page', [PageAuthController::class, 'registerBypage'])->name('register-throught-page');


Route::get('/auth/social/{provider}/redirect', [AuthController::class, 'socialRedirect'])->name('social.redirect');
Route::get('/auth/social/{provider}/callback', [AuthController::class, 'socialCallback'])->name('social.callback');
Route::middleware(['auth'])->group(function () {
    Route::get('/loc', [HomeController::class, 'setupLocationFromBrowser']);
    Route::get('/loc-list', [HomeController::class, 'zipCodeList']);
    Route::get('/origin-list', [PostController::class, 'originList']);
    Route::get('/change-location/{zipcode}', [HomeController::class, 'changeLocation']);

    Route::get('/edit-user-profile', [PageAuthController::class, 'editUserProfile'])->name('user.profile');
    Route::post('/edit-user-profile', [PageAuthController::class, 'updateUserProfile'])->name('user.profile-save');

    Route::middleware(['auth', 'adminUser'])->group(function () {
        Route::prefix('/admin')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\UsersController::class, 'index'])->name('admin.home');
            Route::get('/users', [\App\Http\Controllers\Admin\UsersController::class, 'index'])->name('admin.users-list');
            Route::get('/user/{id}', [\App\Http\Controllers\Admin\UsersController::class, 'viewUser'])->name('admin.user-detail');

            Route::get('/posts', [\App\Http\Controllers\Admin\PostsController::class, 'index'])->name('admin.post-list');
            Route::get('/category-wise-posts', [\App\Http\Controllers\Admin\PostsController::class, 'categoriesWisePosts'])->name('admin.categories-list');
            Route::get('/create-post', [\App\Http\Controllers\Admin\PostsController::class, 'createPost'])->name('admin.create-post');
            Route::post('/delete-post', [\App\Http\Controllers\Admin\PostsController::class, 'deletePost'])->name('admin.delete-post');
            Route::post('/unpublish-post', [\App\Http\Controllers\Admin\PostsController::class, 'unPublishPost'])->name('admin.unpublish-post');
            Route::post('/publish-post', [\App\Http\Controllers\Admin\PostsController::class, 'publishPost'])->name('admin.publish-post');


            Route::get('/preview-post/{type}/{slug}', [PostController::class, 'previewPost'])->name('admin.preview-post');


            Route::get('/facebook-page-posts', [PostController::class, 'getFacebookPagePosts'])->name('admin.facebook-page-posts');
        });
    });

    Route::middleware(['zipcodeLookup'])->group(function () {
        Route::get('/', [HomeController::class, 'index'])->name('home');
        Route::get('/load-more', [HomeController::class, 'loadMorePosts'])->name('load-more-posts');




        Route::get('/community-centers', [CommunityCentersController::class, 'index'])->name('community-centers');
        Route::get('/events', [CommunityCentersController::class, 'index'])->name('events');
        Route::middleware(['auth'])->group(function () {
            Route::get('/create-post', [PostController::class, 'createPost'])->name('create-post');
            Route::post('/save-post', [PostController::class, 'savePost'])->name('save-post');
            Route::post('/like-post', [PostController::class, 'likePost'])->name('like-post');

            Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        });


        Route::get('/post/{id}', [PostController::class, 'postDetails'])->name('post-details');
        Route::get('/{type}/{slug}', [PostController::class, 'viewPostPage'])->name('view-detail-page');
        Route::get('/user/{id}', [CommunityCentersController::class, 'index'])->name('user-details');
    });
});



### TO CLEAR CACHE ###
Route::get('/clear-cache', function () {
    $exitCode = Artisan::call('cache:clear');
    $exitCode = Artisan::call('config:cache');
    $exitCode = Artisan::call('route:clear');
    $exitCode = Artisan::call('optimize');
    return 'DONE';
});
// Views Testing routes
Route::get('/404', function () {
    return view('errors.404');
});
Route::get('/site-settings', function () {
    return view('admin.settings.site-setting');
});
