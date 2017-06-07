<?php
/**
 * @file
 * Events repository contains business logic regarding to calendarevents feature related.
 */

namespace App\Repositories;

use Exception;
use App\Models\EventModel as EventModel;
use App\Models\StateModel;
use App\Models\UserModel;
use Auth;
use Carbon\Carbon as Carbon;

class EventRepository
{

    protected $eventModel;
    protected $orderJobRepository;
    protected $eventTypeRepository;
    protected $userRepository;
    protected $stateModel;

    /**
     * Class dependency injection.
     */
    public function __construct()
    {
        $this->eventModel = new EventModel();
        $this->orderJobRepository = new OrderJobRepository();
        $this->eventTypeRepository = new EventTypeRepository();
        $this->userRepository = new UserRepository();
        $this->stateModel = new StateModel();
    }

    /**
     * Return all active eager loaded permissions.
     *
     * @param array $input the input array from request object.
     *
     * @return mixed Array of calendar events data.
     */
    public function getEventsForCalendar($input)
    {

        $staffFilters = !empty($input['staff_filters']) ? $input['staff_filters'] : [];
        $eventTypeFilters = !empty($input['event_type_filters']) ? $input['event_type_filters'] : [];

        $events = $this->eventModel->published()->with(['user', 'eventType']);

        // Add conditions.
        if (!empty($input['account_manager_filter'])) {
            // Account manager filter is single.
            $events->where(function ($query) use ($input) {
                $query->where('created_by', '=', $input['account_manager_filter'])
                    ->orWhereHas('orderJobElement.orderJob.order', function ($query) use ($input) {
                        $query->where('account_manager_id', '=', $input['account_manager_filter']);
                    });
            });
        }
        if (!empty($input['start'])) {
            $events->where('to', '>=', $input['start']);
        }

        if (!empty($input['end'])) {
            $events->where('from', '<', $input['end']);
        }
        // ------------------

        // Let's talk about event assigned related.
        $events->where(function ($query) use ($input, $staffFilters) {
            $query->whereIn('user_id', $staffFilters)
                ->orWhere(function ($query) use ($input) {
                    $notAssignedStateFilters = !empty($input['not_assigned_state_filters']) ? $input['not_assigned_state_filters'] : [];
                    $query->where(function ($query) {
                        $query->whereNull('user_id')->orWhere('user_id', '=', 0);
                    })->whereIn('state_id', $notAssignedStateFilters);
                });
        });

        $events->whereIn('event_type_id', $eventTypeFilters);

        return $this->transformEventsForCalendar($events->get());
    }

    /**
     * Get all unscheduled events.
     *
     * @return Eloquent collection $events unscheduled events.
     */
    public function getUnscheduledEvents()
    {
        $events = $this->eventModel->unscheduled()->get();
        return $events;
    }

    /**
     * Get all on hold events.
     *
     * @return Eloquent collection $events on hold events.
     */
    public function getOnHoldEvents()
    {
        $events = $this->eventModel->onHold()->get();
        return $events;
    }

    /**
     * Get all unpublished events.
     *
     * @return Eloquent collection $events unpublished events.
     */
    public function getUnpublishedEvents()
    {
        $events = $this->eventModel->with(['user', 'orderJobElement.orderJob.order'])->unpublished()->get();
        return $events;
    }

    /**
     * Get all the need to verify events
     *
     * @param $stateId id of the state.
     *
     * @return Eloquent collection $events
     */
    public function getEventsNeedToBeVerified($stateId)
    {
        $relations = [
            'eventStatus',
            'user',
            'state',
            'eventType',
            'endOfDayNotes',
            'orderJobElement.orderJob',
            'orderJobElement.orderJob.video',
            'orderJobElement.orderJob.orderJobAssets',
            'orderJobElement.orderJob.order',
            'orderJobElement.orderJob.product',
            'orderJobElement.orderJob.order.office',
            'orderJobElement.orderJob.order.accountManager',
            'orderJobElement.orderJob.state',
        ];

        $from = Carbon::now()->subDays(env('EOD_ADMIN_DAYS_LIMIT', 30))->toDateTimeString();
        $query = $this->eventModel->published()->with($relations)->NotVerified()->where('to', '<=', Carbon::yesterday()->endOfDay())->where('to', '>', $from);

        // Exclude Inroom product type as they are outsourced and we do not need them to add EOD at all.
        $query->whereHas('orderJobElement.orderJob', function ($query) {
            $query->where('product_id', '<>', config('constants.IN_ROOM_AUCTION_PRODUCT_ID'));
        });

        $query->whereHas('orderJobElement.orderJob.state', function ($query) use ($stateId) {
            $query->where('state_in_charge', $stateId);
        });

        $query->whereHas('orderJobElement.orderJob.product', function ($query) {
            $query->where('is_video_product', '1');
        });

        $query->orderBy('events.from', 'DESC');

        return $query->get();
    }

    /**
     * Filter out events against the event type id.
     *
     * @param collection $events event collection.
     * @param Array $eventTypeIds the event type id array.
     *
     * @return Collection filtered event collection.
     */
    public function filterEventByType($events, $eventTypeIds)
    {
        $returnEvents = [];
        if (!empty($events)) {
            foreach ($events as $event) {
                if (in_array($event->event_type_id, $eventTypeIds)) {
                    $returnEvents[] = $event;
                }
            }
        }
        return $returnEvents;
    }

    /**
     * Filter out the events only return event which does not has video product and eod note.
     *
     * @param collection $events event collection.
     *
     * @return Collection filtered event collection.
     *
     */
    public function filterEventWithOutVideoAndEod($events)
    {
        $returnEvents = [];
        foreach ($events as $event) {
            if (($event->endOfDayNotes->count() == 0) && empty($event->orderJobElement->orderJob->video->id)) {
                $returnEvents[] = $event;
            }
        }

        return $returnEvents;
    }

    /**
     * Filter out events only return the event which has video product and eod note.
     *
     * @param collection $events event collection.
     *
     * @return Collection filtered event collection.
     *
     */
    public function filterEventWithVideoAndEod($events)
    {
        $returnEvents = [];
        foreach ($events as $event) {
            if (($event->endOfDayNotes->count() > 0) && !empty($event->orderJobElement->orderJob->video->id)) {
                $returnEvents[] = $event;
            }
        }

        return $returnEvents;
    }

    /**
     * Filter out events only return the event which has video product but doesnot has eod note.
     *
     * @param collection $events event collection.
     *
     * @return Collection filtered event collection.
     *
     */
    public function filterEventWithVideoAndWithoutEod($events)
    {
        $returnEvents = [];
        foreach ($events as $event) {
            if (($event->endOfDayNotes->count() == 0) && !empty($event->orderJobElement->orderJob->video->id)) {
                $returnEvents[] = $event;
            }
        }

        return $returnEvents;
    }

    /**
     * Filter out all the events only return the event which does not has video product but has eod note.
     *
     * @param collection $events event collection.
     *
     * @return Collection filtered event collection.
     *
     */
    public function filterEventWithoutVideoAndWithEod($events)
    {
        $returnEvents = [];
        foreach ($events as $event) {
            if (($event->endOfDayNotes->count() > 0) && empty($event->orderJobElement->orderJob->video->id)) {
                $returnEvents[] = $event;
            }
        }

        return $returnEvents;
    }

    /**
     * Filter out the events only return event which has eod and eod type is event.
     *
     * @param collection $events event collection.
     *
     * @return Collection filtered event collection.
     *
     */
    public function filterEventOnlyHasEventEod($events)
    {
        $returnEvents = [];
        foreach ($events as $event) {
            if ($event->endOfDayNotes->count() > 0) {
                $eodTypeIds = [];
                foreach ($event->endOfDayNotes as $eod) {
                    $eodTypeIds[] = $eod->end_of_day_type_id;
                }
                if (!in_array(config('constants.END_OF_DAY_TYPE_MEDIA'), $eodTypeIds) && (in_array(config('constants.END_OF_DAY_TYPE_EVENT'), $eodTypeIds))) {
                    $returnEvents[] = $event;
                }
            }
        }

        return $returnEvents;
    }

    /**
     * Filter out the events only return event which has eod and eod type is media.
     *
     * @param collection $events event collection.
     *
     * @return Collection filtered event collection.
     *
     */
    public function filterEventOnlyHasMediaEod($events)
    {
        $returnEvents = [];
        foreach ($events as $event) {
            if ($event->endOfDayNotes->count() > 0) {
                $eodTypeIds = [];
                foreach ($event->endOfDayNotes as $eod) {
                    $eodTypeIds[] = $eod->end_of_day_type_id;
                }
                if (in_array(config('constants.END_OF_DAY_TYPE_MEDIA'), $eodTypeIds) && (!in_array(config('constants.END_OF_DAY_TYPE_EVENT'), $eodTypeIds))) {
                    $returnEvents[] = $event;
                }
            }
        }

        return $returnEvents;
    }

    /**
     * Filter out the events only return event which doesn't has eod.
     *
     * @param collection $events event collection.
     *
     * @return Collection filtered event collection.
     *
     */
    public function filterEventWithoutEod($events)
    {
        $returnEvents = [];
        foreach ($events as $event) {
            if (($event->endOfDayNotes->count()) == 0) {
                $returnEvents[] = $event;
            }
        }
        return $returnEvents;
    }

    /**
     * Filter out the events have both event and media eod.
     *
     * @param collection $events event collection.
     *
     * @return Collection filtered event collection.
     *
     */
    public function filterEventWithMediaAndEod($events)
    {
        $returnEvents = [];
        foreach ($events as $event) {
            if ($event->endOfDayNotes->count() > 0) {
                $eodTypeIds = [];
                foreach ($event->endOfDayNotes as $eod) {
                    $eodTypeIds[] = $eod->end_of_day_type_id;
                }
                if (in_array(config('constants.END_OF_DAY_TYPE_MEDIA'), $eodTypeIds) && (in_array(config('constants.END_OF_DAY_TYPE_EVENT'), $eodTypeIds))) {
                    $returnEvents[] = $event;
                }
            }
        }
        return $returnEvents;
    }

    /**
     * Get the events need to approve.
     *
     * @param $stateId id of the state.
     *
     * @return Eloquent collection $events
     */
    public function getEventsNeedToBeApproved($stateId)
    {
        $eventRelations = [
            'eventStatus',
            'user',
            'state',
            'eventType',
            'orderJobElement.orderJob',
            'orderJobElement.orderJob.state',
            'orderJobElement.orderJob.Product',
            'orderJobElement.orderJob.order',
            'orderJobElement.orderJob.order.office',
            'orderJobElement.orderJob.order.accountManager'
        ];
        $eventTypeIds = $this->eventTypeRepository->getRecordingEventTypes();
        $query = $this->eventModel->with($eventRelations)->whereIn('event_type_id', $eventTypeIds)->NotApproved();

        $query->whereHas('orderJobElement.orderJob', function ($query) {
            $query->whereNotNull('order_id');
        });

        $events = $this->filterEventsToIncludeEditStateEvents($query->get(), $stateId);
        return $events;
    }

    /**
     * Filter the events based on the state in charge and
     * also include the event if alternate_edit_state_id is in the user state in charge states.
     *
     * @param $events non filtered events.
     * @param $stateId the user state id.
     *
     * @return array of eloquent objects for events.
     */
    private function filterEventsToIncludeEditStateEvents($events, $stateId)
    {
        $stateInChargeStatesIdsArr = $this->getStateInChargeStatesIds($stateId);
        $eventsArr = [];
        foreach ($events as $event) {
            if (in_array($event->orderJobElement->orderJob->state_id, $stateInChargeStatesIdsArr)
                || in_array($event->orderJobElement->orderJob->alternate_edit_state_id, $stateInChargeStatesIdsArr)
            ) {
                $eventsArr[] = $event;
            }
        }
        return $eventsArr;
    }

    /**
     * Get the state in charge ids based on the given user state id.
     *
     * @param $stateId id of the user state.
     *
     * @return array of the state ids.
     */
    public function getStateInChargeStatesIds($stateId)
    {
        return !empty($stateId) ? $this->stateModel->where('state_in_charge', $stateId)->lists('id')->toArray() : $stateId;
    }


    /**
     * Get user uncompleted past events
     *
     * @param $userId id of the user
     *
     * @return Eloquent collection $events.
     */
    public function getUserOverdueEvents($userId)
    {
        if (empty($userId)) {
            $userId = Auth::user();
        }
        $returnEvents = $this->getScheduledEventsListBaseQuery($userId)->uncompleted()->where('to', '<=', Carbon::yesterday()->endOfDay())->get();

        return $returnEvents;
    }

    /**
     * Get user today's events
     *
     * @param $userId id of the user
     *
     * @return Eloquent collection $events.
     */
    public function getUserTodayEvents($userId)
    {
        if (empty($userId)) {
            $userId = Auth::user();
        }
        $start = Carbon::today()->startOfDay();
        $end = Carbon::today()->endOfDay();
        $returnEvents = $this->getScheduledEventsListBaseQuery($userId)
            ->where('from', '>=', $start)->where('from', '<=', $end)
            ->orderBy('from', 'ASC')
            ->get();

        return $returnEvents;
    }

    /**
     * Get user coming events
     *
     * @param $userId id of the user
     *
     * @return Eloquent collection $events.
     */
    public function getUserFutureEvents($userId)
    {
        if (empty($userId)) {
            $userId = Auth::user();
        }
        $start = Carbon::tomorrow()->startOfDay();
        $end = Carbon::tomorrow()->addDays(7)->endOfDay();
        $returnEvents = $this->getScheduledEventsListBaseQuery($userId)
            ->where('from', '>=', $start)->where('from', '<=', $end)
            ->orderBy('from', 'ASC')
            ->get();

        return $returnEvents;
    }

    /**
     * Get the default query string for uncompleted events.
     *
     * @param $userId id of the user
     * @return Illuminate\Database\Eloquent\Builder
     */
    private function getScheduledEventsListBaseQuery($userId)
    {
        $relations = [
            'eventStatus',
            'user',
            'state',
            'eventType',
            'orderJobElement.orderJob',
            'orderJobElement.orderJob.orderJobAssets',
            'orderJobElement.orderJob.order',
            'orderJobElement.orderJob.order.office',
            'orderJobElement.orderJob.order.accountManager',
            'endOfDayNotes',
            'orderJobElement.orderJob.order.attachments'
        ];
        $query = $this->eventModel->with($relations)->published()->where('events.user_id', '=', $userId);

        return $query;
    }

    /**
     * Filter and keep only unscheduled events and categorised by state id.
     *
     * @param eloquent $events eloquent object for events.
     *
     * @return array the array consists of event eloquent objects by state.
     */
    public function filterUnscheduledEventsByStates($events)
    {
        $unscheduledEvents = $this->filterEventsByEventStatus($events, config('constants.EVENT_STATUS_ID_UNSCHEDULED'));

        return $this->categorizeEventsByState($unscheduledEvents);
    }

    /**
     * Filter and keep only onhold events and categorised by state id.
     *
     * @param eloquent $events eloquent object for events.
     *
     * @return array the array consists of event eloquent objects by state.
     */
    public function filterOnHoldEventsByStates($events)
    {
        $onholdEvents = $this->filterEventsByEventStatus($events, config('constants.EVENT_STATUS_ID_ON_HOLD'));

        return $this->categorizeEventsByState($onholdEvents);
    }

    /**
     * Categorize events by state id.
     *
     * @param eloquent $events eloquent object for events.
     *
     * @return array $returnEvents.
     */
    public function categorizeEventsByState($events)
    {
        $returnEvents = [];
        if (!empty($events)) {
            foreach ($events as $event) {
                $stateName = !empty($event->state->name) ? strtolower($event->state->name) : 'other';

                if (!empty($returnEvents[$stateName])) {
                    $returnEvents[$stateName][] = $event;
                } else {
                    $returnEvents[$stateName] = [
                        $event
                    ];
                }
            }
        }

        return $returnEvents;
    }

    /**
     * Filter events by event status id.
     *
     * @param Eloquent $events eloquent query result.
     * @param Integer $eventStatusId the id of the event status.
     *
     * @return Array $returnEvents array of events eloquent objects.
     */
    public function filterEventsByEventStatus($events, $eventStatusId)
    {
        $returnEvents = [];

        if (!empty($events)) {
            foreach ($events as $event) {
                if ($event->event_status_id == $eventStatusId) {
                    $returnEvents[] = $event;
                }
            }
        }

        return $returnEvents;
    }

    /**
     * Filter out and only return events for NSW.
     *
     * @param collection $events event collection.
     * @param Integer $eventStatusId the event status id.
     *
     * @return Collection filtered event collection.
     */
    public function filterBayEventsNsw($events, $eventStatusId)
    {
        $returnEvents = [];
        foreach ($events as $event) {
            if (($event->event_status_id == $eventStatusId) && $event->state_id == config('constants.STATE_ID_NSW')) {
                $returnEvents[] = $event;
            }
        }
        return $returnEvents;
    }

    /**
     * Filter out and only return events for non NSW.
     *
     * @param collection $events event collection.
     * @param Integer $eventStatusId the event status id.
     *
     * @return Collection filtered event collection.
     */
    public function filterBayEventsOtherStates($events, $eventStatusId)
    {
        $returnEvents = [];
        foreach ($events as $event) {
            if (($event->event_status_id == $eventStatusId) && $event->state_id != config('constants.STATE_ID_NSW')) {
                $returnEvents[] = $event;
            }
        }
        return $returnEvents;
    }

    /**
     * Load event by id.
     *
     * @param integer $id event id.
     *
     * @return mixed Eloquent object or fail.
     */
    public function loadById($id)
    {
        return $this->eventModel->with([
            'user',
            'eventType',
            'orderJobElement',
            'orderJobElement.orderJob',
            'orderJobElement.orderJob.product',
            'orderJobElement.orderJob.order',
            'orderJobElement.orderJob.order.accountManager',
            'orderJobElement.orderJob.video',
            'state',
        ])->findOrFail($id);
    }

    /**
     * Create event from data array.
     *
     * @param array $data the data match with eloquent model fields.
     *
     * * @return mixed created record.
     */
    public function createEvent($data)
    {
        return $this->eventModel->create($data);
    }

    /**
     * Update event by event id and data array.
     *
     * @param Integer $id the id of the event.
     * @param array $data the data match with eloquent model fields.
     *
     * @return mixed update result. True, if updated.
     */
    public function updateEvent($id, $data)
    {
        $event = $this->eventModel->with([
            'orderJobElement.orderJob',
        ])->findOrFail($id);
        $event->update($data);
        $this->checkAndUpdateJobEvents($event);
        return $event;
    }

    /**
     * Delete Event by id.
     *
     * @param Integer $id the id of the event.
     */
    public function deleteEventById($id)
    {
        return $this->eventModel->findOrFail($id)->delete();
    }

    /**
     * Update Event Status.
     *
     * @param Eloquent $event Event object.
     * @param Integer $eventStatusId the id of the event status.
     *
     * @return mixed, the result of the update.
     */
    public function updateEventStatus($event, $eventStatusId)
    {
        $event->event_status_id = $eventStatusId;
        return $event->save();
    }

    /**
     * Approve event.
     *
     * @param mixed $event id of the event or the eloquent object.
     */
    public function approve($event)
    {
        if (empty($event->id)) {
            $event = $this->loadById($event);
        }
        $event->update([
            'is_approved' => 1,
        ]);
    }

    /**
     * Return Event names for suggestion.
     *
     * @param String $text the text to search for in event name field.
     *
     * @return array $eventNames unique event name for suggestion.
     */
    public function eventNameSearchSuggestion($text)
    {
        $events = EventModel::select('name')->contains('name', $text)->distinct()->take(config('constants.SEARCH_SUGGESTION_LIMIT'))->get();
        $eventNames = [];
        foreach ($events as $event) {
            if (!empty($event->name)) {
                // Need to cast
                $eventNames[] = trim($event->name);
            }
        }
        return $eventNames;
    }

    /**
     * Search events by keywords, either by order id or event key words.
     *
     * @param mixed $keyword order id or event keywords.
     *
     * @return eloquent collection for events.
     */
    public function searchEvents($keyword)
    {

        $events = $this->eventModel->with(['eventStatus', 'user', 'state', 'eventType', 'orderJobElement.orderJob', 'orderJobElement.orderJob.order', 'orderJobElement.orderJob.order.office', 'orderJobElement.orderJob.order.accountManager']);
        if (is_numeric($keyword)) {
            $events->whereHas('orderJobElement.orderJob.order', function ($query) use ($keyword) {
                $query->contains('id', (int)$keyword);
            });
        } else {
            $events->contains('name', $keyword);
        }
        $events->orderBy('events.name');
        return $events;
    }

    /**
     * Schedule Event to new date time and keep event length.
     *
     * @param Integer $id the id of the event.
     * @param String $newFrom the new event from in datetime format.
     *
     * @return Mixed update event result.
     */
    public function scheduleEventToDateTime($id, $newFrom)
    {
        return $this->updateEventToDateTime($id, $newFrom, true);
    }

    /**
     * Schedule Event to new date time and keep event length.
     *
     * @param Mixed $eventData the id of the event or eloquent object.
     * @param String $newFrom the new event from in datetime format.
     * @param boolean true to mark event as scheduled.
     *
     * @return Mixed update event result.
     */
    public function updateEventToDateTime($eventData, $newFrom, $scheduleEvent = false)
    {
        if (!empty($eventData->id)) {
            $event = $eventData;
        } else {
            $event = $this->loadById($eventData);
        }

        $newFrom = Carbon::createFromFormat('Y-m-d H:i:s', $newFrom);
        $diffInSec = $event->to->diffInSeconds($event->from);
        $event->from = $newFrom->toDateTimeString();
        $event->to = $newFrom->addSeconds($diffInSec)->toDateTimeString();
        if ($scheduleEvent == true) {
            $event->event_status_id = config('constants.EVENT_STATUS_ID_SCHEDULED');
        }
        $event->save();
        return $event;
    }

    /**
     * Check to get date time change Url.
     *
     * @param Integer $id the id of the event.
     * @param Array $inputData Event details from form input.
     *
     * @return mixed datatime change email URL or NULL
     */
    public function checkAndGetDateTimeChangeUrl($id, $inputData)
    {
        $event = $this->loadById($id);
        $isJobShoot = $this->isJobShootEvent($event);
        $isEventTimeChanged = $this->checkEventDateTimeChange($event, $inputData);
        if ($isJobShoot && $isEventTimeChanged) {
            return route('orders.viewDatetimeEmail', ['order_id' => $event->orderJobElement->orderJob->order_id]);
        }
    }

    /**
     * Check event date time change by checking old event data with input data.
     *
     * @param Eloquent $event the eloquent object for event.
     * @param Array $inputData the input data from the update form popup.
     *
     * @return Boolean TRUE if date time changed.
     */
    public function checkEventDateTimeChange($event, $inputData)
    {
        return ($event->from != $inputData['from']) || ($event->to != $inputData['to']);
    }

    /**
     * Return unpublished events only.
     */
    public function filterUnpublishedEvents($events)
    {
        $unpublishedEvents = [];

        if (!empty($events)) {
            foreach ($events as $event) {
                if ($event->event_status_id != config('constants.EVENT_STATUS_ID_SCHEDULED')) {
                    $unpublishedEvents[] = $event;
                }
            }
        }
        return $unpublishedEvents;
    }

    /**
     * Unassign job events.
     *
     * @param Eloquent $orderJob orderjob model
     */
    public function unassignJobEvents($orderJob)
    {
        foreach ($orderJob->orderJobElements as $orderJobElement) {
            if ($orderJobElement->events->count() > 0) {
                foreach ($orderJobElement->events as $event) {
                    $event->user_id = null;
                    $event->save();
                }
            }
        }
    }

    /**
     * Function to transform eloquent format for calendar events array.
     *
     * @param array $events array of Event data.
     *
     * @return array $calenarEventsarray of calendar events data.
     */
    private function transformEventsForCalendar($events)
    {
        $calendarEvents = [];
        foreach ($events as $event) {
            // Set the event tooltip title.
            $detail = $this->processEventDetail($event);
            $title = $this->processEventTitle($event);

            $calendarEvents[] = [
                'id' => isset($event->id) ? $event->id : null,
                'title' => $title,
                'detail' => $detail,
                'eventType' => !empty($event->eventType->name) ? strtolower($event->eventType->name) : '',
                'allDay' => isset($event->is_all_day) ? $event->is_all_day : null,
                'start' => isset($event->from) ? $event->from->toDateTimeString() : null,
                'end' => isset($event->to) ? $event->to->toDateTimeString() : null,
                'className' => isset($event->id) ? 'event-' . $event->id : '',
                'backgroundColor' => !empty($event->user->calendar_color) ? $event->user->calendar_color : config('constants.CALENDAR_EVENT_DEFAULT_BACKGROUND_COLOR'),
                'orderId' => !empty($event->orderJobElement->orderJob->order->id) ? $event->orderJobElement->orderJob->order->id : null,
            ];
        }
        return $calendarEvents;
    }

    /**
     * Process event title.
     *
     * @param Eloquent $event event object.
     *
     * @return String $title.
     */
    private function processEventTitle($event)
    {
        try {
            $titleArray = [];

            if (!empty($event->orderJobElement->orderJob->order->order_status_id) && ($event->orderJobElement->orderJob->order->order_status_id == config('constants.ORDER_CANCELLED'))) {
                $titleArray[] = 'ORDER CANCELLED';
            }

            if (!empty($event->eventType->name)) {
                $titleArray[] = $event->eventType->name;
            }

            if (!empty($event->name)) {
                $titleArray[] = $event->name;
            }

            return implode(' - ', $titleArray);
        } catch (Exception $e) {
            return 'Error occur';
        }
    }

    /**
     * Process event detail. Example usage. Event tooltip.
     *
     * @param Eloquent $event event object.
     *
     * @return String $detail.
     */
    private function processEventDetail($event)
    {
        try {
            $detailArray = [];
            if (!empty($event->orderJobElement->orderJob->order->order_status_id) && ($event->orderJobElement->orderJob->order->order_status_id == config('constants.ORDER_CANCELLED'))) {
                $detailArray[] = 'ORDER CANCELLED';
            }
            if (!empty($event->eventType->name)) {
                $detailArray[] = $event->eventType->name;
            }
            if (!empty($event->user->full_name)) {
                $detailArray[] = $event->user->full_name;
            }
            if (!empty($event->orderJobElement->orderJob->order->accountManager->full_name)) {
                $detailArray[] = $event->orderJobElement->orderJob->order->accountManager->full_name;
            }
            if (!empty($event->orderJobElement->orderJob->order->office->name)) {
                $detailArray[] = $event->orderJobElement->orderJob->order->office->name;
            }

            $eventDetail = implode(' - ', $detailArray);

            // Adding Shoot Event Id to the Shoot Related array.
            $shootAndRelatedArr = array_merge([config('constants.EVENT_TYPE_ID_SHOOT')], get_shoot_related_event_type_ids());

            if (in_array($event->event_type_id, $shootAndRelatedArr) === false) {
                if (!empty($event->orderJobElement->orderJob->production_date)) {
                    $eventDetail .= '<br>'.displayDayDateTimePeriodFormat($event->orderJobElement->orderJob->production_date);
                }
            }

            return $eventDetail;
        } catch (Exception $e) {
            return 'Error occur';
        }
    }

    /**
     * Check and update job events if the event is from an order, and is main shoot event.
     *
     * @param Object $event eloquent data object, eager loaded with event types, and order job details.
     */
    private function checkAndUpdateJobEvents($event)
    {

        $isJobShoot = $this->isJobShootEvent($event);
        if ($isJobShoot === true) {
            // Update job production date.
            $jobData = [
                'production_date' => $event->from,
            ];

            $this->orderJobRepository->updateOrderJob($event->orderJobElement->order_job_id, $jobData);

            // When the shoot is updated
            // then all the Shoot related (Additional Shooter, Shoot Assistant, Producer, Drone and Model Talent)
            // events should follow.
            $this->updateJobEventsTimeForShootRelatedEvents($event);
        }
    }

    /**
     * Check whether this is job shoot event.
     *
     * @param Eloquent $event event object.
     *
     * @return Boolean TRUE if is job shoot event.
     */
    private function isJobShootEvent($event)
    {
        return $this->isJobEvent($event) && ($event->event_type_id == config('constants.EVENT_TYPE_ID_SHOOT'));
    }

    /**
     * Check whether the event is from order job.
     *
     * @param Eloquent $event event object.
     *
     * @return Boolean TRUE if is job shoot event.
     */
    private function isJobEvent($event)
    {
        return !empty($event->order_job_element_id);
    }

    /**
     * Update job event time exclude main shoot event.
     *
     * @param Eloquent eager loaded shoot event.
     */
    private function updateJobEventsTimeForShootRelatedEvents($jobShootEvent)
    {
        $orderJobId = !empty($jobShootEvent->orderJobElement->orderJob->id) ? $jobShootEvent->orderJobElement->orderJob->id : null;
        if (!empty($orderJobId)) {
            $events = $this->loadShootRelatedEvents($orderJobId)->get();

            foreach ($events as $event) {
                $this->updateEventToDateTime($event, $jobShootEvent->from);
            }
        }
    }

    /**
     * Load job events exclude main shoot event.
     *
     * @param Integer $orderJobId the order job id.
     *
     * @return eloquent collection with events or empty.
     */
    private function loadShootRelatedEvents($orderJobId)
    {
        $events = $this->eventModel
            ->with([
                'orderJobElement.orderJob'
            ])
            ->whereIn('event_type_id', get_shoot_related_event_type_ids())
            ->whereHas('orderJobElement.orderJob', function ($query) use ($orderJobId) {
                $query->where('id', '=', $orderJobId);
            });
        return $events;
    }

    /**
     * Get all the tomorrow order events.
     *
     * @param Array $eventTypes
     *
     * @return eloquent collection with events or empty.
     */
    public function getTomorrowOrderEvents($eventTypes = NULL)
    {
        $returnEvents = null;
        $relations = [
            'eventStatus',
            'user',
            'orderJobElement.orderJob',
            'orderJobElement.orderJob.order',
            'orderJobElement.orderJob.order.office',
            'orderJobElement.orderJob.order.accountManager'
        ];
        $query = $this->eventModel->whereNotNull('order_job_element_id')->where('event_status_id', '=', config('constants.EVENT_STATUS_ID_SCHEDULED'));

        if(!empty($eventTypes) && is_array($eventTypes)) {
            $query = $query->whereIn('event_type_id', $eventTypes);
        }

        $query = $query->with($relations)->published();

        $start = Carbon::Tomorrow()->startOfDay();
        // If today is friday, time range of the events should be saturday to monday.
        if (Carbon::Tomorrow()->dayOfWeek == Carbon::SATURDAY) {
            $end = Carbon::parse('next monday')->endOfDay();
        } else {
            $end = Carbon::Tomorrow()->endOfDay();
        }

        $returnEvents = $query->where('from', '>=', $start)->where('from', '<=', $end)->orderBy('from', 'ASC')->get();

        return $returnEvents;
    }

    /**
     * Group the events by account_manager_id and team_leader_id.
     *
     * @param Eloquent $events event object.
     *
     * @return eloquent collection with events or empty
     */
    public function groupEventsByStaffId($events)
    {
        $returnEvent = null;
        foreach ($events as $event) {
            if (!empty($event->orderJobElement->orderJob->order->account_manager_id)) {
                $returnEvent[$event->orderJobElement->orderJob->order->account_manager_id][] = $event;
            }
            // If for an order, the account manager and team leader is the same person, that's fine. It just override.
            if (!empty($event->orderJobElement->orderJob->order->team_leader_id)) {
                $returnEvent[$event->orderJobElement->orderJob->order->team_leader_id][] = $event;
            }
        }

        return $returnEvent;
    }
}
