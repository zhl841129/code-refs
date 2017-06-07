<?php
/**
 * @file
 *
 * This command is used to send notification email to the account manager and team leader for the tomorrow events.
 */

namespace App\Console\Commands;

use App\Repositories\EventRepository;
use App\Repositories\UserRepository;
use App\Repositories\OrderJobRepository;
use App\Repositories\OrderRepository;
use Illuminate\Console\Command;
use App\Jobs\EmailSender;
use Illuminate\Bus\Dispatcher;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Log;
use View;

class ShooterIntroductionEmail extends Command
{
    use DispatchesJobs;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:shooter-introduction-email';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Shooter introduction email will be sent at 3pm with 1 day prior to production, but Friday will notify Saturday, Sunday and Monday. This is only triggered on Weekdays.';


    protected $eventRepository;
    protected $orderJobRepository;
    protected $orderRepository;
    protected $userRepository;

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();
        $this->eventRepository = new EventRepository();
        $this->orderJobRepository = new OrderJobRepository();
        $this->orderRepository = new OrderRepository();
        $this->userRepository = new UserRepository();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        try {
            Log::info('------- Start process of shooter introduction email on ' . date('Y-m-d') . '-------');

            $events = $this->eventRepository->getTomorrowOrderEvents([config('constants.EVENT_TYPE_ID_SHOOT')]);

            if ($events->count() > 0) {
                $eventsByOrderId = $this->groupEventsByOrderId($events);

                foreach($eventsByOrderId as $orderId => $events) {
                    $this->processShooterIntroductionEmail($events);
                }

            } else {
                echo 'No events found for tomorrow for account managers'.PHP_EOL;
                Log::info('No events found for tomorrow for account managers' . PHP_EOL);
            }
        } catch (\Exception $e) {
            echo 'Failed to notify account managers for tomorrow events: ' . $e->getMessage() . PHP_EOL;
            Log::error('Failed to notify account managers for tomorrow events: ' . $e->getMessage() . PHP_EOL);
        }
    }

    /**
     * Group events by order Id.
     *
     * @param Eloquent $events model query results.
     *
     * @return Array array of events categorised by order id.
     */
    private function groupEventsByOrderId($events) {
        $eventsByOrderId = [];
        if($events->count() > 0) {
            foreach($events as $event) {
                if(!empty($event->orderJobElement->orderJob->order_id)) {
                    if(empty($eventsByOrderId[$event->orderJobElement->orderJob->order_id])) {
                        $eventsByOrderId[$event->orderJobElement->orderJob->order_id] = [];
                    }
                    $eventsByOrderId[$event->orderJobElement->orderJob->order_id][] = $event;
                }
            }
        }

        return $eventsByOrderId;
    }

    /**
     * Process shooter introduction email. Process per order.
     *
     * @param Array $events array of event model object.
     *
     * @return Boolean.
     */
    private function processShooterIntroductionEmail($events) {

        try {
            $order = $events[0]->orderJobElement->orderJob->order;

            $to = $order->AllJobContactEmails;
            $cc = $this->getEmailCcs($events);
            $replyTo = $this->getEmailReplyTo($events[0]);
            $optional = array(
               'cc' => $cc,
               'replyTo' => $replyTo,
            );

            $eventTypeIds = [
                config('constants.EVENT_TYPE_ID_PRODUCER'),
                config('constants.EVENT_TYPE_ID_SHOOT'),
                config('constants.EVENT_TYPE_ID_ADDITIONAL_SHOOTER'),
            ];

            $jobCrewsByEventTypes = $this->orderJobRepository->loadJobCrewsCategorisedByEventTypes($events[0]->orderJobElement->orderJob, $eventTypeIds);

            $primaryCrew = $this->getPrimaryCrew($jobCrewsByEventTypes);
            $meetCrews = $this->getMeetCrews($jobCrewsByEventTypes, $eventTypeIds);
            $am = $events[0]->orderJobElement->orderJob->order->accountManager;

            $this->sendNotificationEmail($to, $optional, $events, $primaryCrew, $meetCrews, $am);

            return TRUE;
        }
        catch(\Exception $e) {
            $this->sendErrorEmail($events, $e);
            return FALSE;
        }
    }

    /**
     * Get primary crew. Producer then shooter then nothing.
     *
     * @param Array $jobCrewsByEventTypes
     *
     * @return Eloquent $user.
     */
    private function getPrimaryCrew($jobCrewsByEventTypes) {
        $user = NULL;
        if(isset($jobCrewsByEventTypes[config('constants.EVENT_TYPE_ID_PRODUCER')]) && !empty($jobCrewsByEventTypes[config('constants.EVENT_TYPE_ID_PRODUCER')])) {
            $user = array_pop($jobCrewsByEventTypes[config('constants.EVENT_TYPE_ID_PRODUCER')]);
        }
        elseif($jobCrewsByEventTypes[config('constants.EVENT_TYPE_ID_SHOOT')]) {
            $user = array_pop($jobCrewsByEventTypes[config('constants.EVENT_TYPE_ID_SHOOT')]);
        }

        return $user;
    }

    /**
     * Get meet crews. Producer then shooter then nothing. Producer => shooter => additional shooter.
     *
     * @param Array $jobCrewsByEventTypes
     * @param Array $eventTypeIds the event types (Order is important.
     *
     * @return Eloquent $user.
     */
    private function getMeetCrews($jobCrewsByEventTypes, $eventTypeIds = array()) {
        $users = array();
        if(!empty($eventTypeIds)) {
            foreach($eventTypeIds as $eventTypeId) {
                $crews = !empty($jobCrewsByEventTypes[$eventTypeId]) ? $jobCrewsByEventTypes[$eventTypeId] : array();
                $users = array_merge($users, $crews);
            }
        }

        return $users;
    }

    /**
     * Get email ccs. Shoot CREW, TL and AM.
     *
     * @param Array $events array of event model eloquent.
     *
     * @return Array $ccList array of emails need to be cced.
     */
    private function getEmailCcs($events) {
        $ccList = array();
        if(!empty($events)) {
            foreach($events as $event) {
                if(!empty($event->id)) {
                    if(!empty($event->user->email) && !in_array($event->user->email, $ccList)) {
                        $ccList[] = $event->user->email;
                    }
                    if(!empty($event->orderJobElement->orderJob->order->accountManager->email) && !in_array($event->orderJobElement->orderJob->order->accountManager->email, $ccList)) {
                        $ccList[] = $event->orderJobElement->orderJob->order->accountManager->email;
                    }
                    if(!empty($event->orderJobElement->orderJob->order->teamLeader->email) && !in_array($event->orderJobElement->orderJob->order->teamLeader->email, $ccList)) {
                        $ccList[] = $event->orderJobElement->orderJob->order->teamLeader->email;
                    }
                    $productions = $this->userRepository->getUsersByRolesAndStates(
                        [
                            config('constants.USER_ROLE_ID_PRODUCTION')
                        ],
                        [
                            $event->state->state_in_charge
                        ]
                    );

                    if($productions->count() > 0) {
                        $productionEmails = $productions->lists('email')->toArray();
                        Log::info(json_encode($productions));
                        $ccList = array_merge($ccList, $productionEmails);
                    }
                }
            }
        }


        return array_unique($ccList);
    }

    /**
     * Get email reply to.
     *
     * @param Eloquent $event event model eloquent.
     *
     * @return Array $replyTo.
     */
    private function getEmailReplyTo($event) {
        $replayTo = array();
        if(!empty($event->orderJobElement->orderJob->order->accountManager->email)) {
            $replayTo['name'] = $event->orderJobElement->orderJob->order->accountManager->full_name;
            $replayTo['email'] = $event->orderJobElement->orderJob->order->accountManager->email;
        }
        return $replayTo;
    }

    /**
     * Send shooter introduction error email.
     *
     * @param Array $events array of event model eloquent object.
     * @param Exception $e.
     */
    private function sendErrorEmail($events, $e) {
        $to = config('constants.EMAIL_GROUP_GENERAL_ERROR');
        $subject = 'Error occur when send shooter introduction email. order id #' . $events[0]->orderJobElement->orderJob->order_id;
        $body = $e->getMessage();
        $job = (new EmailSender($subject, $body, $to))->onQueue(config('constants.QUEUE_EMAIL_SENDER'));
        $this->dispatch($job);
    }

    /**
     * Seand notification email.
     *
     * @param Array $to array of emails.
     * @param Array $optional array containing replyTo etc.
     * @param Array $events array of event model object.
     * @param Eloquent $primaryCrew user model.
     * @param Array $meetCrews user models array.
     * @param Eloquent $am User model object for account manager.
     */
    private function sendNotificationEmail($to, $optional, $events, $primaryCrew, $meetCrews, $am)
    {
        // $events are from the same order grouped.
        $subject = 'FILMING REMINDER: ' . $events[0]->orderJobElement->orderJob->order->name;

        $body = View::make('emails.notifications.shoot-introduction', compact('primaryCrew', 'meetCrews', 'am', 'events'))->render();

        $job = (new EmailSender($subject, $body, $to, $optional))->onQueue(config('constants.QUEUE_EMAIL_SENDER'));
        $this->dispatch($job);
    }
}
