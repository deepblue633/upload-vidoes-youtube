<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Google\Client;
use Google\Service\YouTube;
use Google\Service\YouTube\Video;
use Google\Service\YouTube\VideoSnippet;
use Google\Service\YouTube\VideoStatus;
use Google\Http\MediaFileUpload;
use Illuminate\Support\Facades\Storage;

class YouTubeController extends Controller
{
    public function uploadVideo(Request $request)
    {
        $request->validate([
            'video' => 'required|file|mimetypes:video/mp4,video/avi,video/mpeg',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $client = new Client();
        $client->setAuthConfig(Storage::path('credentials.json')); // Assuming credentials.json is in the storage/app directory
        $client->addScope(YouTube::YOUTUBE_UPLOAD);

        if ($request->session()->has('access_token')) {
            $client->setAccessToken($request->session()->get('access_token'));

            if ($client->isAccessTokenExpired()) {
                $request->session()->forget('access_token');
                return redirect()->route('youtube.auth');
            }
        } else {
            return redirect()->route('youtube.auth');
        }

        $youtube = new YouTube($client);

        $videoPath = $request->file('video')->getPathname();
        $video = new Video();
        $video->setSnippet(new VideoSnippet());
        $video->getSnippet()->setTitle($request->input('title'));
        $video->getSnippet()->setDescription($request->input('description'));
        $video->getSnippet()->setTags(explode(',', $request->input('tags'))); // Assuming tags are comma-separated
        $video->setStatus(new VideoStatus());
        $video->getStatus()->setPrivacyStatus('private');

        $chunkSizeBytes = 1 * 1024 * 1024;

        $client->setDefer(true);
        $insertRequest = $youtube->videos->insert('status,snippet', $video);

        $media = new MediaFileUpload(
            $client,
            $insertRequest,
            'video/*',
            null,
            true,
            $chunkSizeBytes
        );
        $media->setFileSize(filesize($videoPath));

        $status = false;
        $handle = fopen($videoPath, "rb");
        while (!$status && !feof($handle)) {
            $chunk = fread($handle, $chunkSizeBytes);
            $status = $media->nextChunk($chunk);
        }
        fclose($handle);
        $client->setDefer(false);

        if ($status && ($status instanceof Video)) {
            // Video uploaded successfully
            return redirect()->back()->with('success', 'Video uploaded successfully!');
        } else {
            return redirect()->back()->with('error', 'Video upload failed.');
        }
    }

    public function auth()
    {
        $client = new Client();
        $client->setAuthConfig(Storage::path('credentials.json'));
        $client->addScope(YouTube::YOUTUBE_UPLOAD);
        $client->setRedirectUri('http://127.0.0.1:8000/youtube/callback');

        $authUrl = $client->createAuthUrl();
        return redirect()->away($authUrl);
    }

    public function callback(Request $request)
    {
        $client = new Client();
        $client->setAuthConfig(Storage::path('credentials.json'));
        $client->addScope(YouTube::YOUTUBE_UPLOAD);
        $client->setRedirectUri('http://127.0.0.1:8000/youtube/callback');

        $client->authenticate($request->input('code'));
        $request->session()->put('access_token', $client->getAccessToken());

        return redirect()->route('upload.form');
    }
    public function showUploadForm()
    {
        return view('upload'); // This assumes your upload form view is named 'upload.blade.php' 
    }
}
