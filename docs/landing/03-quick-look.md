---
view: components.packages.quick-look
title: A quick look
subtitle: A user saves a post with a note, and the feed shows what they saved.
file: routes/web.php
language: php
code: |
  use App\Post;

  Route::post('posts/{post}/bookmark', function (Post $post) {
      $bookmark = request()->user()->bookmark($post, [
          'note' => request('note'),
          'remind' => request()->boolean('remind'),
      ]);

      return ['note' => $bookmark->meta->note];
  })->middleware('auth');

  Route::get('posts', function () {
      return Post::withBookmarkedBy(request()->user())->latest()->paginate();
  });
---
