<?php
/**
 * @file
 *
 * This controller contain actions related to Calendar.
 */

namespace App\Http\Controllers;

use App\Http\Requests;

use App\Repositories\UserRepository;
use App\Repositories\EventTypeRepository;
use App\Repositories\StateRepository;
use App\Repositories\EventRepository;

use Illuminate\Http\Request;
use Response;

class CalendarController extends Controller
{

    protected $userRepository;
    protected $eventTypeRepository;
    protected $stateRepository;
    protected $eventRepository;

    /**
     * Calendar controller constructor for dependency injections.
     */
    public function __construct()
    {
        $this->userRepository = new UserRepository();
        $this->eventTypeRepository = new EventTypeRepository();
        $this->stateRepository = new StateRepository();
        $this->eventRepository = new EventRepository();
    }

    /**
     * Action for calendar main page.
     */
    public function index()
    {
        $this->authorize('view_calendar');
        $usersGrouped = $this->userRepository->getGroupedUsersForCalendarFilter();
        $eventTypes = $this->eventTypeRepository->getEventTypesList();
        $stateList = $this->stateRepository->getStatesList();
        $accountManagerList = $this->userRepository->getAccountManagersList();

        $unpublishedEvents = $this->eventRepository->getUnpublishedEvents();

        $unscheduledEventsByState = $this->eventRepository->filterUnscheduledEventsByStates($unpublishedEvents);
        $onHoldEventsByState = $this->eventRepository->filterOnHoldEventsByStates($unpublishedEvents);

        return view('calendar.pages.index', compact('usersGrouped', 'eventTypes', 'accountManagerList', 'stateList', 'unscheduledEventsByState', 'onHoldEventsByState'));
    }

    /**
     * Fetch events by fullcalendar.
     *
     * @param Request $request the request object.
     *
     * @return String Json encoded events data.
     */
    public function fetchEvents(Request $request)
    {
        $events = $this->eventRepository->getEventsForCalendar($request->input());
        return Response::json($events);
    }
}
