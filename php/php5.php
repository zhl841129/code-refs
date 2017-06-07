<?php
/**
 * @file
 *
 * This is a job to get total view count.
 */

namespace App\Jobs;

use App\Jobs\Job;
use Illuminate\Queue\SerializesModels;

use Log, Cache;
use App\Services\MetaCDN;
use App\Repositories\VideoRepository;

class VideoTotalViewCount extends Job
{
    use SerializesModels;

    protected $cdn;
    protected $video;
    protected $videoRepository;
    const CACHE_LIFE_MINUTES = 60*4;

    /**
     * Constructor for firing emails.
     *
     * @param Integer $videoId.
     * @param CDNContract $cdn cdn interface.
     */
    public function __construct($videoId)
    {
        $this->cdn = new MetaCDN();
        $this->videoRepository = new VideoRepository();
        $this->setVideo($videoId);
    }

    /**
     * Setter for video object.
     *
     * @param Integer $videoId the id of the video.
     */
    protected function setVideo($videoId) {
        $this->video = $this->videoRepository->loadVideo($videoId);
    }

    /**
     * Execute the email job.
     *
     * @return void
     */
    public function handle()
    {
        $count = 0;

        try {
            $cacheKey = 'video-view-count -' . $this->video->id;

            if(Cache::has($cacheKey)) {
                $count = Cache::get($cacheKey);
            }
            else {
                $cdnMedia = $this->cdn->getMedia($this->video->videoRevisions->first()->cdn_key);
                // Get the metaCDN stats.
                $metaCdnStats = $this->cdn->getMediaViewedCount($cdnMedia->mediaKey, $cdnMedia->createdTime);

                // Get youtube stats.
                if (!empty($this->video->youtube_key)) {
                    $youtubeStats = json_decode(requestYoutubeVideoStatistics($this->video->youtube_key));
                }
                // Get total stats count form youtube and meta cdn.
                $statsCount = (!empty($youtubeStats->items[0]->statistics->viewCount)) ? ($metaCdnStats + $youtubeStats->items[0]->statistics->viewCount) : $metaCdnStats;

                $count = !empty($statsCount) ? $statsCount : 0;
                Cache::put($cacheKey, $count, self::CACHE_LIFE_MINUTES);
            }

        } catch (Exception $e) {
            Log::error('Failed VideoTotalViewCount with ' . $e->getMessage());
            // Handle error.
        }

        return $count;
    }
}
