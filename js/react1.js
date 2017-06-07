import React from 'react';
import fetch from 'isomorphic-fetch'

class MonthVideoSummaryMostPopularVideo extends React.Component {

    /**
     * Constructor for ex6 class.
     */
    constructor(props) {
        super(props)
        this.state = {
            loading: true,
            video_link: null,
            video_view_count: null
        }
    }

    /**
     * Lifecycle method right after the Virtual DOM => DOM
     */
    componentDidMount() {
        fetch('api/client-users/most-popular-video-in-current-month', {
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'Cache': 'no-cache'
            },
            credentials: 'include'
        })
        .then(response => response.json())
        .then(json => this.setState({
            loading: false,
            video_link: json.video_link,
            video_view_count: json.video_view_count
        }))
    }

    /**
     * Render loader.
     */
    renderLoader() {
        return (
            <div>
                <div className="uppercase video-summary__heading">
                    Most Popular
                </div>
                <div>
                    <span className="loader-wrapper">
                        <span className="loader inline-block-style"></span>
                    </span>
                </div>
            </div>
        )
    }

    /**
     * Render count value.
     */
    renderCount() {
        return (
            <div>
                <div className="uppercase video-summary__heading">
                    Most Popular
                </div>
                <div className="video-summary__cell-data">
                    <span>{ this.state.video_view_count }</span> <span>views</span>
                </div>
                <div className="video-summary__most-popular-video__view-button">
                    <a className="btn btn-primary button-primary button-xs" href={ this.state.video_link } target="_blank">VIEW</a>
                </div>
            </div>
        )
    }

    /**
     * Component render function.
     */
    render() {
        return (this.state.loading == true) ? this.renderLoader() : this.renderCount()
    }

}

export default MonthVideoSummaryMostPopularVideo