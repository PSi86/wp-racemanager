// script structure: load RHData, processRHData, updateClassData, calculateClassLayout, renderNodes, updateFilterAndHighlight

let timestamp_cached, timestamp_server, cr_rh_data, firstLoad, socket;
let selectedPilotId = null; // Holds the currently selected pilot ID
let rh_scroller = null; // Scroller object for smooth scrolling

let hostedOn, timestampUrl, dataUrl, refreshInterval, webmode;

// Fallback for when we are outside WordPress and wp_localize_script hasn't run.
if (typeof wp_vars !== 'undefined') {
    // script beeing delivered by WordPress
    hostedOn = "web";
    timestampUrl = wp_vars.timestampUrl;
    dataUrl = wp_vars.dataUrl;
    refreshInterval = wp_vars.refreshInterval; // Update interval in milliseconds

    if(wp_vars.webmode=="live") {
        // live mode
        webmode = wp_vars.webmode;
    }
    else {
        // default to static mode (wp_vars.webmode=="static")
        webmode = "static";
        //cr_rh_data = wp_vars.data;
    }
}
else {
    // script beeing delivered by RotorHazard
    webmode = ""; // not needed in RH version
    console.log("Using default values for timestampUrl, dataUrl and refreshInterval.");
    // script beeing delivered by RotorHazard
    timestampUrl = ""; // not needed in RH version
    dataUrl = ""; // not needed in RH version
    refreshInterval = 10000; // not needed in RH version
}

class Scroller {
    // Example usage
    /* const progressBar = document.getElementById('progress-bar');
    const scroller = new Scroller(2000, progressBar); */

    /* const toggleButton = document.getElementById('toggle-button');
    toggleButton.addEventListener('click', () => {
        scroller.setScrolling(!scroller.isScrollingActive); // Toggle scrolling state
    }); */

    // Example of changing anchors
    // scroller.setAnchors(['newAnchor1', 'newAnchor2']); // First anchor should be the one not in view before calling this (further down on the page)

    constructor(scrollInterval, progressBar, defaultAnchors = ['looser-bracket', 'winner-bracket']) {
        this.scrollInterval = scrollInterval; // Time between scrolls in milliseconds
        this.progressBar = progressBar; // DOM element for the progress bar
        this.isScrollingActive = false; // Current scrolling state
        this.timer = null;
        this.progress = 0;
        this.anchors = defaultAnchors; // Array of anchor IDs
        this.currentAnchorIndex = 0; // Tracks which anchor to scroll to next
        this.microStep = 20; // Progress bar update interval in milliseconds
    }

    setScrolling(enable) {
        // Ignore redundant state changes
        if (enable === this.isScrollingActive) {
            return;
        }

        if (enable) {
            this.startScrolling();
        } else {
            this.stopScrolling();
        }
    }

    startScrolling() {
        this.isScrollingActive = true;
        const progressStep = 100 / (this.scrollInterval / this.microStep); // Increment per 50ms update

        this.timer = setInterval(() => {
            this.progress += progressStep;

            if (this.progress >= 100) {
                this.progress = 0;
                this.scrollToNextAnchor();
            }

            this.progressBar.style.width = `${this.progress}%`;
        }, this.microStep); // 50ms update interval for smooth progress bar
    }

    stopScrolling() {
        this.isScrollingActive = false;
        clearInterval(this.timer);
        this.timer = null;
        this.progress = 0;
        this.currentAnchorIndex = 0; // if stopped, reset to the first anchor (scroll down)
        this.progressBar.style.width = '0%';
    }

    scrollToNextAnchor() {
        const nextAnchorId = this.anchors[this.currentAnchorIndex];
        const anchorElement = document.getElementById(nextAnchorId);

        if (anchorElement) {
            anchorElement.scrollIntoView({ behavior: 'smooth' });
        }

        // Alternate between the two anchors
        this.currentAnchorIndex = (this.currentAnchorIndex + 1) % this.anchors.length;
    }

    setAnchors(anchorIds) {
        if (Array.isArray(anchorIds) && anchorIds.length === 2) {
            this.anchors = anchorIds;
            this.currentAnchorIndex = 0; // Reset to the first anchor
        } else {
            console.error("setAnchors expects an array of two anchor IDs.");
        }
    }
}

function initData() {
    // do things that are only necessary once per page load
    // cr_rh_data=null; // TEST for offline mode (cr_rh_data is set in wp_vars)
    firstLoad=true;
    if(hostedOn=="web") {
        // if hosted on web use sessionStorage and ajax to fetch the data
        // check if cached data is available and load it
        //loadScripts("");
        initWebVersion();
    
    }
    else if(hostedOn=="rh") {
        // if hosted on RH use websocket to get the data
        //loadScripts("bracketview/static/");
        initRHVersion();
    }
}

function initWebVersion() {
    //initData();
    // Hide RH controls
    document.querySelectorAll(".rh-controls").forEach((element) => {
        element.style.display = "none";
    });

    //if(webmode=="live") {
    // Use cache also in static mode
    if(webmode) {
        // Load cached data if available
        timestamp_cached=sessionStorage.getItem("cr_rh_timestamp"); // returns null if not found
        cr_rh_data=JSON.parse(sessionStorage.getItem("cr_rh_data")); // returns null if not found
    }

    // Load user settings if available and update UI (no change events are triggered)
    selectedPilotId = parseInt(sessionStorage.getItem("cr_selectedPilotId")) || 0; // default to 0
    //document.getElementById("pilotSelector").value = selectedPilotId; // needs to be done after the data is loaded so the options are available
        
    filterCheckboxState = JSON.parse(sessionStorage.getItem("cr_filterCheckboxState")) || false; // default to false
    document.getElementById("filterCheckbox").checked = filterCheckboxState;

    // If cached data is available, render it and start fetching new data afterwards
    //if(timestamp_cached !== null && cr_rh_data !== null) {
    if(cr_rh_data !== null) { // TEST for offline mode (generally the presence of cr_rh_data is enough)
        console.log("Cached data found. Rendering cache and start checking for new data.");
        processRHData(cr_rh_data); // processes the data, then triggers rendering
    }

/*     if(webmode=="live") { // TEST for offline mode
        // Start standard update routine
        loadWebData(); // async function to fetch new data
    } */

    loadWebData(webmode); // async function to fetch data. running periodically in live mode, once in static mode

    // Only attach new mouse eventhandler once after the data is loaded
    attachPilotMouseEvents(); // attach mouse events to the pilot names
}

function initRHVersion() {
    //initData();
    // hide the pilot selector and filter checkbox by hiding all elements with the class "controls"
    document.querySelectorAll(".web-controls").forEach((element) => {
        element.style.display = "none";
    });

    const progressBar = document.getElementById('progress-bar');
    rh_scroller = new Scroller(10000, progressBar);

    selectedPilotId = 0; // default to 0
    filterCheckboxState = false; // default to false
    // init websocket connection
    // init websocket callbacks
    // init websocket subscription

    // TODO reduce number of data_dependencies as much as possible
    let data_dependencies = [
        'class_data',
		'current_heat',
        'format_data',
        'frequency_data',
        'heat_data',
		'pilot_data',        
        'result_data'
	];

    cr_rh_data = {}; // initialize empty data object

    // startup socket connection
	socket = io.connect(location.protocol + '//' + document.domain + ':' + location.port);
    
    // reconnect when visibility is regained
	$(document).on('visibilitychange', function(){
		if (!document['hidden']) {
			if (!socket.connected) {
 				socket.connect();
			}
		}
	});

    // hard reset
	socket.on('database_restore_done', function (msg) {
		location.reload();
	});

	// load needed data from server when required
	socket.on('load_all', function (msg) {
		if (typeof(data_dependencies) != "undefined") {
			socket.emit('load_data', {'load_types': data_dependencies});
		}
	});

	// store language strings
	socket.on('all_languages', function (msg) {
		cr_rh_data.language = msg;
	});

    socket.on('class_data', function (msg) {
        if (!$.isEmptyObject(msg.classes)) {
            cr_rh_data.class_data = msg;
            checkDataComplete();
        }
    });
    socket.on('current_heat', function (msg) {
        if (!$.isEmptyObject(msg)) {
            cr_rh_data.current_heat = msg;
            checkDataComplete();
        }
    });
    socket.on('format_data', function (msg) {
        if (!$.isEmptyObject(msg.formats)) {
            cr_rh_data.format_data = msg;
            checkDataComplete();
        }
    });
    socket.on('frequency_data', function (msg) {
        if (!$.isEmptyObject(msg.fdata)) {
            cr_rh_data.frequency_data = msg;
            checkDataComplete();
        }
    });
    socket.on('heat_data', function (msg) {
        if (!$.isEmptyObject(msg.heats)) {
            cr_rh_data.heat_data = msg;
            checkDataComplete();
        }
    });
    socket.on('pilot_data', function (msg) {
        if (!$.isEmptyObject(msg.pilots)) {
            cr_rh_data.pilot_data = msg;
            checkDataComplete();
        }
    });
    socket.on('result_data', function (msg) {
        if (!$.isEmptyObject(msg.heats)) {
            cr_rh_data.result_data = msg;
            checkDataComplete();
        }
    });

    function checkDataComplete() {
		// Check if all data is loaded
        // 'class_data', 'current_heat', 'format_data', 'frequency_data', 'heat_data', 'pilot_data', 'result_data'
        let complete = true;
        data_dependencies.forEach(function (item) {
            if (typeof(cr_rh_data[item]) == "undefined") {
                if(item != "result_data") {
                    complete=false; // result_data is optional
                }
            }
        });
        if (complete) {
            processRHData(cr_rh_data);
        }
    }
}

// BEGIN load RHData
// Only used when hosted on the web
async function loadWebData(mode="static") {
    // refresh all variables by loading latest data from the server
    
    //timestamp_server=await getServerTimestamp(); //using ajax for now. better timeout handling

    // firstLoad is important for the user notification for new data.
    // if firstLoad is true, the new data will be shown instantly and the user will be notified: "Data refreshed"
    //  until the remote data has been loaded a notification will be shown: "Loading data..."
    // if firstLoad is false, the new data will not be shown automatically, only the user will be notified: "New data available"

    if(timestamp_cached==null || cr_rh_data==null) { // cache is empty or incomplete
        console.log("No or incomplete cached data found.");
    }
    else{ // cache has data
        console.log("Cached timestamp found.");  
    }

    function getServerTimestampAjax(){
        // return the ajax promise
        return jQuery.ajax({
            dataType: "json",
            url: timestampUrl,
            cache : false,
            method: "GET",
            timeout: 5000
       });
    }

    function getServerDataAjax(){
        // return the ajax promise
        return jQuery.ajax({
            dataType: "json",
            url: dataUrl,
            cache : false,
            method: "GET",
            timeout: 5000
       });
    }

    getServerTimestampAjax()
        .done(function(data){
            timestamp_server=data.time;
            if(mode=="live") {
                setTimeout(function() { loadWebData("live"); }, refreshInterval);
            }
            if(timestamp_server!=timestamp_cached || cr_rh_data==null) {
                console.log("Server and local timestamps are different.");
                timestamp_cached=timestamp_server;
                sessionStorage.setItem('cr_rh_timestamp', timestamp_server);

                // connection to server works and new data is available -> fetch new data
                getServerDataAjax()
                    .done(function(data){
                        cr_rh_data = data; // TEST 
                        sessionStorage.setItem('cr_rh_data', JSON.stringify(data));
                        console.log("Successfully fetched and saved new rh_data.");
            
                        // TODO check if data is complete?
                        if(firstLoad==true) {
                            // Show a message in a bottom indicator bar
                            // "Data refreshed."
                            firstLoad=false;
                            console.log("Auto display new data after fresh page load.");
                            processRHData(cr_rh_data); // processes the data, then triggers rendering
                        }
                        else {
                            console.log("New data retrieved, user can update page on demand.");
                            // TODO show a message in a bottom indicator bar enabling the user to refresh the page on demand
                            //push_message("<div id=\"update_page\">Neue Daten verfügbar.</div>", false);
                            // for now just render the data
                            processRHData(cr_rh_data); // processes the data, then triggers rendering
                        }
                    })
                    .fail(function(data){
                        console.log("Error retrieving Data from the Server.");
                        // TODO show a message in a bottom indicator bar
                        // "Connection Error."
                    });
            }
            else{
                console.log("Server and local timestamps are the same.");
                firstLoad=false; // no need to skip the new data message because the data is already up to date, next time there is new data, the user will be notified
            }
        })
        .fail(function(data){
            console.log("Error retrieving Data from the Server.");
    
            // TODO show a message in a bottom indicator bar
            // "Connection Error."
            setTimeout(function() { loadWebData(); }, 2000); // on error retry after 2 seconds (in static mode and live mode)
        });
}

function getCurrentClassName() {
    // get the currently active class from the RHData
    function findRaceClassByHeatId(id) {
        const race = cr_rh_data.heat_data.heats.find(cls => cls.id === id); // .class_data. instead of .classes.?
        return race ? race.class_id : null;
    }
    let currentClassId=findRaceClassByHeatId(cr_rh_data.current_heat.current_heat);
    if(currentClassId) {
        const currentClass=cr_rh_data.class_data.classes.find(cls => cls.id === currentClassId);
        return currentClass.displayname.toLowerCase();
    } 
    else {
        return null;
    }
}

function updateAllClasses() {
    // find raceclass-container on the page and update them
    const classContainers = document.getElementsByClassName("raceclass-container");
    for (let i = 0; i < classContainers.length; i++) {
        //updateClass(classContainers[i]); // Object instead of ID
        if(classContainers[i].style.display != "none") {
            // only update visible class-displays
            updateClass(classContainers[i].id); // ID instead of Object
        }
    }
}

function updateClass(containerId) {
    const raceClass = containerId.substring(0, containerId.indexOf("-display")); // eg:"elimination"
    console.log("Updating class display: " + containerId);
    // Todo: Accept class parameter to update only one class or empty parameter for all classes
    nodes=updateClassData(cr_rh_data, raceClass);
    nodes=filterBracketData(nodes);
    nodes=calculateClassLayout(nodes);
    //renderGrid(nodes);
    renderGrid(nodes, raceClass);
}

function updateSvgScaling() {
    // find all svg elements and redraw them
    console.log("Redrawing SVG elements");
    // TODO: implement svg redraw
    // to do this we need the nodes data for each class-display container -> locally store them per container
    // workaround: recreate nodes data for each container and redraw the svg elements using the standard renderGrid function
    updateAllClasses();
}

// Function to update bracketData with eliminationHeats
// Copy necessary data from Input (rhData) to Output (bracketData)
// Original heat id is stored in .rh_id per heat
// Current heat id is stored in .currentHeat

// TODO eliminate writing to global variable bracketData, use return value instead or use a variable for this class only instead of overwriting the global template
function updateClassData(rhData, raceClass) { // rhData is the data from RotorHazard, raceClass is the name of the class to be displayed
    if (!rhData || !raceClass) {
        console.error("updateClassData: Missing rhData or raceClass");
        return null;
    }

    // TODO detect correct template (clone it)
    // rename to Template from bracketData to de32_template.json, de16_template.json, general_template.json
    
    let bracketData, numPilots, template;
    bracketData = { settings: {}, data: [] };
    if(raceClass=="elimination") {
        numPilots = rhData.pilot_data.pilots.length;
        // only the elimination class uses specific templates
        if(numPilots<=16)                   { bracketData.data = structuredClone(de16_template); template="de16"; }
        if(numPilots>16 && numPilots<=32)   { bracketData.data = structuredClone(de32_template); template="de32"; }
    }
    // no template found for this class
    if(bracketData.data.length == 0) { 
        bracketData.data = structuredClone(default_template); // empty array
        template="default"; 
    }
   
    //bracketData = bracketTemplates[raceClass]; // clone the template
    console.log("updateClassData: using "+template+" template for class: "+raceClass);

    bracketData.settings.currentHeat = rhData.current_heat.current_heat;
    //bracketData.settings.currentClass
    bracketData.settings.template = template;

    // Function to find the ID of the class named "Elimination"
    function findClassIdByName(data, raceClassCapital) {
        const raceClass = data.class_data.classes.find(cls => cls.displayname === raceClassCapital); // .class_data. instead of .classes.?
        return raceClass ? raceClass.id : null;
    }
    function findRaceClassByName(data, raceClassCapital) {
        const raceClass = data.class_data.classes.find(cls => cls.displayname === raceClassCapital); // .class_data. instead of .classes.?
        return raceClass ? raceClass : null;
    }    
    function findRaceClassByHeatId(data, id) {
        const race = heat_data.heats.find(cls => cls.id === id); // .class_data. instead of .classes.?
        return race ? race.class_id : null;
    }
    // Function to filter heats by class ID
    function filterHeatsByClassId(heats, classId) {
        return heats.filter(heat => heat.class_id === classId);
    }

    // Find the class ID for the classname
    const raceClassCapital=raceClass.charAt(0).toUpperCase() + raceClass.slice(1);
    const raceClassObj = findRaceClassByName(rhData, raceClassCapital);

    if(!raceClassObj) { 
        console.error("updateClassData: raceClass not found in RHData");
        return null;  // Exit if the class ID is not found
    }
    const raceClassId = raceClassObj.id;

    // TODO rename eliminationHeats to something more generic
    const eliminationHeats = filterHeatsByClassId(rhData.heat_data.heats, raceClassId);
    // Sort eliminationHeats by id
    eliminationHeats.sort((a, b) => a.id - b.id);

    // Convert rhData.result_data.heats to an array if it's an object
    let resultHeatsArray
    if(rhData.result_data) {
        resultHeatsArray = Array.isArray(rhData.result_data.heats) ? rhData.result_data.heats : Object.values(rhData.result_data.heats);
    }
    else {
        resultHeatsArray = null;
    }

    let targetId = 1; // Initialize targetId to 1

    eliminationHeats.forEach((heat, index) => {

        if (template=="default") {
            // Default Template has no elements -> Create a new element
            //targetElement = JSON.parse(JSON.stringify(default_template[0]));
            const newNode={};
            newNode.id = targetId;
            newNode.pilots = [];
            bracketData.data.push(newNode);
        }

        // Search for the targetId in winner and looser elements
        let targetElement = bracketData.data.find(node => node.id === targetId);

        if (!targetElement) {
            throw new Error(`Target element with id=${targetId} not found in bracketData`);
        }

        // Update the target element with the new data
        targetElement.rh_id = heat.id; // Add the new rh_id element
        targetElement.title = heat.displayname; // Map displayname to title

        // add flag for active heat (used in rendering)
        // TODO: check if this works also when the active heat changes during runtime (will the flag be added to the next active heat and keep it on the last one, too?)
        if (heat.id === bracketData.settings.currentHeat) {
            targetElement.active = true;
        }

        // rhData.result_data.heats.find
        const heatResults = resultHeatsArray ? resultHeatsArray.find(h => h.heat_id === heat.id) : null;
        let leaderboard = null;
        let time = null;

        if (heatResults && heatResults.leaderboard.meta.primary_leaderboard) {
            switch (heatResults.leaderboard.meta.primary_leaderboard) {
                case "by_race_time": // Time based leaderboard
                    leaderboard = heatResults.leaderboard.by_race_time;
                    time = "total_time";
                    break;
                case "by_consecutives": // Consecutive based leaderboard
                    leaderboard = heatResults.leaderboard.by_consecutives;
                    time = "consecutives";
                    break
                case "by_fastest_lap": // Fastest lap based leaderboard
                    leaderboard = heatResults.leaderboard.by_fastest_lap;
                    time = "fastest_lap";
                    break;
                default:
                    leaderboard = heatResults.leaderboard.by_race_time;
                    time = "total_time";
            }
        }

        let flownRounds = 0; // number of rounds in the results
        let heatResultAvailable = false; // flag for results availability
        
        if(heatResults) {
            flownRounds = heatResults.rounds.length > 0 ? heatResults.rounds.length : 0;
            heatResultAvailable = true;
        }
        else {
            flownRounds = 0;
            heatResultAvailable = false;
        }

        // check class_data for configured number of rounds per heat in this class
        if(raceClassObj.rounds>1) {
            
            targetElement.title += "\n"+flownRounds+" of "+raceClassObj.rounds;
        }

        // Update the pilots array while keeping the structure intact
        heat.slots.forEach((slot, i) => {
            let pilotName = "";
            let pilotResult = "";

            // TODO: first check if results are available for the heat
            // if not, use the seed_id to display the heat displayname and rank
            // if yes, display the pilot callsign and result

            // Switch between seeded and unseeded slots!
            if ((slot.pilot_id === 0 || slot.node_index === null) && !heatResultAvailable) {
                // Slot not seeded
                // could just get this data from the result_data
                //result_data.heats[4].rounds[flownRounds].nodes[i].pilot_id

                // Resolve seed_id to heat displayname
                const matchingHeat = rhData.heat_data.heats.find(h => h.id === slot.seed_id);
                pilotName = matchingHeat ? `${matchingHeat.displayname} Rank ${slot.seed_rank}` : "";
            } 
            //else if (slot.pilot_id !== 0 && slot.node_index !== null && heatResultAvailable) {
            else if (slot.pilot_id !== 0 && slot.node_index !== null) {
                // Slot is seeded
                const pilot = rhData.pilot_data.pilots.find(p => p.pilot_id === slot.pilot_id);
                pilotName = pilot ? pilot.callsign : "";
                if(leaderboard) { // check if results are available
                        const pilotLeaderboard = leaderboard.find(r => r.pilot_id === slot.pilot_id);
                    if (pilotLeaderboard[time] === "0:00.000" || pilotLeaderboard.position < 1) {
                        pilotResult = "DNF";
                    } else {
                        pilotResult = String(pilotLeaderboard[time] + " ");  // total_time
                        pilotResult += String(" #" + pilotLeaderboard.position);
                    }
                }
                //pilotResult = String(pilotLeaderboard[time]+" ");  // total_time
                //pilotResult += pilotLeaderboard.position != null ? String("#"+pilotLeaderboard.position) : "DNF";
            }

            if (targetElement.pilots[i]) {
                targetElement.pilots[i].id = slot.pilot_id;
                targetElement.pilots[i].name = pilotName;
                targetElement.pilots[i].result = pilotResult;
            } else {
                targetElement.pilots.push({
                    id: slot.pilot_id,
                    name: pilotName,
                    result: pilotResult
                });
            }
        });

        // Increment the targetId by the difference between the current and last heat id
        if (index < eliminationHeats.length - 1) {
            targetId += eliminationHeats[index + 1].id - heat.id;
        }

    });

    return bracketData;
}

// 1st function after loading RHData
// always called when new data has been loaded or received.
//  Extracts basic Pilot data and populates the pilot selector
// then it checks the state of the UI and updates the class displays depending on the current class
function processRHData(data) {
    if (!data) {
        console.error("processRHData: Missing data");
        return null;
    }

    // Extract basic Pilot data from the RHData
    const pilotsMap = data.pilot_data.pilots.map(pilot => ({
        id: pilot.pilot_id,
        callsign: pilot.callsign
    }));

    // Sort the pilotsMap alphabetically by callsign
    pilotsMap.sort((a, b) => a.callsign.localeCompare(b.callsign));

    // Example: Populate the pilot selector with data
    const pilotSelector = document.getElementById('pilotSelector');
    pilotsMap.forEach(pilot => {
        const option = document.createElement('option');
        option.value = pilot.id;
        option.textContent = pilot.callsign;
        pilotSelector.appendChild(option);
    });

    //TODO: find better place for this
    pilotSelector.value = selectedPilotId;


    const currentClass = getCurrentClassName();
    if(currentClass && hostedOn=="rh") {
        // If using RH version, show only the current class, hide the others
        // do this before updating the class displays
        showOnlyActiveClass(currentClass);
    }

    // Update the class displays (the visible ones)
    updateAllClasses();

    if(currentClass && hostedOn=="rh") {
        let scrollingNecessarcy = false; // flag to indicate if scrolling is necessary
        // Focus and zoom the current class - especially for double elimination brackets!
        // on Rotorhazard the current class is always displayed exclusively
        // if it does not fit on the screen vertically, then scroll up and down the page in intervals
        // alternate between winner- and looser-bracket display
        scrollingNecessarcy = scaleToFullWidth(document.getElementById(currentClass+"-display"));
        //let scroll_direction = 1; // 1: scroll down, -1: scroll up

        // TODO: Scrolling!!!!!!!!! 
        // needs to be running in a loop with a timer without blocking the UI or other functions
        
        if(scrollingNecessarcy) {
            rh_scroller.setScrolling(true);
        }
        else {
            rh_scroller.setScrolling(false);
            document.body.scrollTop = 0; // For Safari
            document.documentElement.scrollTop = 0; // For Chrome, Firefox, IE and Opera
        }

        function scaleToFullWidth(element) {
            const screenWidth = window.innerWidth;
            const screenHeight = window.innerHeight;
            const elementWidth = element.offsetWidth;
            const elementHeight = element.offsetHeight;
            
            const maxZoom = 2; // Maximum zoom factor
            const marginWidth = 120; // Margin in pixels
            const marginHeight = 20; // Margin in pixels
    
            // Calculate the scale factor to make the element fit the screen width (with 20px margin)
            const scaleWidth = screenWidth / (elementWidth+marginWidth);
    
            // Apply the scale factor, capped by the maxZoom value
            const scale = Math.min(scaleWidth, maxZoom);
            element.style.transform = `scale(${scale})`;
            const body = document.body;
            // Get the current height of the body
            //const currentBodyHeight = parseFloat(window.getComputedStyle(body).height);

            // Set the new height
            body.style.height = `${(elementHeight*scale)+marginHeight}px`;
            // Check if the scaled element height is larger than the screen height
            return (elementHeight*scale) > screenHeight;
        }
    }

// next function: 
}

// Function to update the visibility of the class displays based on the current class
// Only used when hosted on RH
function showOnlyActiveClass(raceClass) {
    // find all class-display containers that are not the current class and hide them
    const classContainers = document.getElementsByClassName("raceclass-container");
    for (let i = 0; i < classContainers.length; i++) {
        if(classContainers[i].id != raceClass+"-display") {
            classContainers[i].style.display = "none";
        }
        else {
            classContainers[i].style.display = "block";
        }
    }
}


// Generate Bracket Representation
// Calculate the grid layout for the bracketData (gridColumn, gridRow)
function calculateClassLayout(bracketData) {
    if (!bracketData) {
        console.error("calculateClassLayout: Missing bracketData");
        return null;
    }

    // TODO make the positioning more generic
    // 1: this should work for all types of brackets 
    //      -> with double elimination we need to have one row between the winner and looser bracket for spacing and maybe a title
    // 2: use winner flag only in bracketData for double elimination brackets
    // 3: use central data element in bracketData to decide what to display (which template is used: doubleElimination, singleElimination, none (for non-bracket))
    
    // TODO add headline for each stage 
    // (first row in the grid of each class)
    // for the bracket the first row of the winner bracket and the first row of the looser bracket
    const nodes = { settings: {}, data: [] };

    if(bracketData.settings.template == "default") {
        // Apply a simple linear layout for the default template
        console.log("calculateClassLayout: applying linear layout for default template");
        
        function positionNodes(classNodes, topOffset) {
            classNodes.data.forEach((node, index) => {
                node.gridColumn = index + 1;
                node.gridRow = 1 + topOffset;
                //node.type = type;
                nodes.data.push(node);
            });
        }

        positionNodes(bracketData, 1);
        nodes.settings = bracketData.settings;
        return nodes;
    }
    else if(bracketData.settings.template == "de16" || bracketData.settings.template == "de32") {
        console.log("calculateClassLayout: applying double elimination layout");
        // Function to calculate parent Y position
        // TODO this should not only use the first parent but all parents and then return the lowest row number
        function getParentYPosition(node, allNodes) {
            if (!node.parents || node.parents.length === 0) return null;
            const parentNode = allNodes.data.find(n => n.id === node.parents[0]);
            return parentNode ? parentNode.gridRow : null;
        }

        // Position nodes
        // TODO: optimize stage logic. for elimination brackets where the hierarchie of some heats is not set by parent definitions, the stage is necessary to determine the position
        // -> for non-bracket race classes the stage is not necessary and the gridColumn can be determined by incrementing a counter (linear display, single row)
        // -> for bracket race classes: cleanup empty stages (can happen when filtering out heats that are not relevant for the current user)
        // -> use 
        function positionNodes(groupedNodes, type, topOffset) {
            Object.entries(groupedNodes).forEach(([stage, stageNodes]) => {
                stageNodes.forEach((node, index) => {
                    node.gridColumn = parseInt(stage, 10);
                    const parentRow = getParentYPosition(node, nodes);
                    node.gridRow = parentRow !== null ? parentRow : index + 1 + topOffset;
                    //node.type = type;
                    nodes.data.push(node);
                });
            });
        }

        // Utility to group nodes by a property
        function groupBy(array, key) {
            return array.reduce((result, currentValue) => {
                const group = currentValue[key];
                if (!result[group]) result[group] = [];
                result[group].push(currentValue);
                return result;
            }, {});
        }

        const groupedWinnerNodes = groupBy(bracketData.data.filter(node => node.winner), "stage");
        const groupedLooserNodes = groupBy(bracketData.data.filter(node => !node.winner), "stage");

        positionNodes(groupedWinnerNodes, "winner", 1);
        const winnerMaxRow = Math.max(...nodes.data.filter(n => n.winner === true).map(n => n.gridRow));
        positionNodes(groupedLooserNodes, "looser", winnerMaxRow + 1);
        nodes.settings = bracketData.settings;
        return nodes;
    }
    else {
        console.error("calculateClassLayout: Unknown template");
        return null;
    }
}

function filterBracketData(bracketData) {
    if (!nodes) {
        console.error("filterBracketData: Missing nodes");
        return null;
    }
    // TODO: when filtering sometimes unnecessary races are shown; also unnecessary blank columns are shown
    const filterPilotId = selectedPilotId;
    const filterActive = filterCheckboxState;
    let filteredBracketData = {};

    if (filterPilotId>0 && filterActive) {
        // Filter and return nodes based on the selected pilot ID, or all nodes if no pilot is selected
        function filterNodes(nodes) {
            const relevantIds = new Set();

            // Step 1: Collect nodes directly associated with the selected pilot
            nodes.forEach(node => {
                if (node.pilots && node.pilots.some(pilot => pilot.id === filterPilotId)) {
                    relevantIds.add(node.id);
                }
            });

            // Step 2: Include all nodes where one of the currently selected races is a parent
            nodes.forEach(node => {
                if (node.parents && node.parents.some(parentId => relevantIds.has(parentId))) {
                    relevantIds.add(node.id);
                }
            });

            // Step 3: Include all child nodes of the selected nodes
            /*
            nodes.forEach(node => {
                if (relevantIds.has(node.id)) {
                    if (node.children) {
                        node.children.forEach(childId => relevantIds.add(childId));
                    }
                }
            });
            */

            // Return filtered nodes
            return nodes.filter(node => relevantIds.has(node.id));
        }

        filteredBracketData.data = filterNodes(bracketData.data)
        filteredBracketData.settings = bracketData.settings;
    } else {
        filteredBracketData = nodes; // Reset to full data
    }
    return filteredBracketData;
    //const nodes = calculateClassLayout(filteredBracketData);
    //renderGrid(nodes);
}

// Render the grid with nodes and connections
// TODO carry over the node content from renderNodes (previous implementation)
function renderGrid(nodes, raceClass) {
    if (!nodes || !raceClass) {
        console.error("renderGrid: Missing nodes or raceClass");
        return null;
    }
    //const containerId = "elimination-display"; // Example container ID for reference
    const containerId = raceClass+"-display";
    const raceClassCapital=raceClass.charAt(0).toUpperCase() + raceClass.slice(1);

    // Clear previous content
    const classContainer = document.getElementById(containerId) || console.error(`renderGrid: Container with ID ${containerId} not found.`);
    classContainer.innerHTML = "";
    //classContainer.replaceChildren(); // Clear the container content
    
    const gridContainer = document.createElement("div");
    gridContainer.setAttribute("id", `${raceClass}-grid`);
    gridContainer.setAttribute("class", "grid-container");
    
    // Map to store node elements for connection lines
    // TODO rework usage of nodeElements, try to eliminate it
    let nodeElements = {};
    // TODO add title to the gridContainer in row 1 and in row winnerMaxRow+1 for bracket classes
    // add style: "grid-column: span 2" to the title element
    
    // Create title element
    const classTitleDiv = document.createElement("div");
    //nodeDiv.style.gridColumn = "1";
    classTitleDiv.style.gridRow = "1";
    classTitleDiv.style.gridColumn = "span 2";
    //classTitleDiv.className = "class-title";
    classTitleDiv.classList.add("class-title");
    classTitleDiv.id = "winner-bracket";
    if(nodes.settings.template === "default") {
        classTitleDiv.textContent = raceClassCapital; // use only the class name for the default template
    } else {
        classTitleDiv.textContent = raceClassCapital+": Winner Bracket"; // Capitalize the first letter
    }
    gridContainer.appendChild(classTitleDiv);

    // Render nodes
    // TODO remove winner and looser classes for classes that are not bracket classes (no template)
    // TODO use index in forEach to iterate through the nodes row by row? 
    // This could avoid the need for the filtering function with its complicated groupBy logic
    nodes.data.forEach(node => {
        // Create node element
        const nodeDiv = document.createElement("div");
        nodeDiv.style.gridColumn = `${node.gridColumn}`;
        nodeDiv.style.gridRow = `${node.gridRow}`;
        //nodeDiv.className = "node";
        nodeDiv.classList.add("node");
        
        //node.type === "winner" ? nodeDiv.classList.add("winner") : nodeDiv.classList.add("looser");
        if(nodes.settings.template == "de16" || nodes.settings.template == "de32") {
            node.winner ? nodeDiv.classList.add("winner") : nodeDiv.classList.add("looser");
        }
        else {
            nodeDiv.classList.add("singlebracket");
        }
        
        if(node.active) nodeDiv.classList.add("activeHeat");

        const nodeTitleDiv = document.createElement("div");
        nodeTitleDiv.textContent = node.title;
        nodeTitleDiv.className = "title";
        nodeDiv.appendChild(nodeTitleDiv);

        // check if pilot-data is available
        if (node.pilots && node.pilots.length > 0) {
            let foundSelectedPilot = false;

            const pilotsContainerDiv = document.createElement("div");
            pilotsContainerDiv.className = "pilots-container";
            //nodeDiv.appendChild(pilotsContainerDiv);

            node.pilots.forEach((pilot, index) => {
                // TODO: special treatment for pilot.id = 0
                // either no "pilot" prefix or skip mouse over event registration
                
                let pilotClass; // classname for the pilot
                if(pilot.id !== 0) {
                    pilotClass = `pilotid-${pilot.id}`; // Unique class for each pilot group
                }
                else {
                    pilotClass = `pilot-notseeded`; // Unique class for each pilot group
                }
                if(pilot.id === selectedPilotId && selectedPilotId !== 0) {
                    // Add a special class for the selected pilot
                    foundSelectedPilot = true;
                }
                const pilotDataDiv = document.createElement("div");
                pilotDataDiv.className = pilotClass;
                pilotDataDiv.classList.add("pilot-entry");
                pilotsContainerDiv.appendChild(pilotDataDiv);

                const nameDiv = document.createElement("div");
                nameDiv.textContent = pilot.name;
                nameDiv.className = "pilot-name";
                pilotDataDiv.appendChild(nameDiv);

                const resultDiv = document.createElement("div");
                resultDiv.textContent = pilot.result || "";
                resultDiv.className = "pilot-result";
                pilotDataDiv.appendChild(resultDiv);
            }); 

            if (!foundSelectedPilot && selectedPilotId !== 0) {
                nodeDiv.classList.add("dimmed");
            }
            
            nodeDiv.appendChild(pilotsContainerDiv);
        }

        // Append node to grid container
        gridContainer.appendChild(nodeDiv);

        // Save node element for connection
        nodeElements[node.id] = nodeDiv;
    });

    // For double elimination brackets, add a title for the looser bracket
    if(nodes.settings.template == "de16" || nodes.settings.template == "de32") {
        // Find the first row number from the 'looser' class
        const looserElements = gridContainer.querySelectorAll('.node.looser');
        let lowestRow = Infinity; // Initialize with a high value
        looserElements.forEach(element => {
            const gridArea = element.style.gridArea;
            const row = parseInt(gridArea.split('/')[0].trim(), 10); // Extract the row number
            if (row < lowestRow) {
                lowestRow = row;
            }
        });                     

        if(lowestRow !== Infinity) {
            lowestRow -= 1; // One row above the looser bracket
            const looserTitleDiv = document.createElement("div");
            looserTitleDiv.style.gridRow = lowestRow;
            looserTitleDiv.style.gridColumn = "span 2";
            //looserTitleDiv.className = "class-title";
            looserTitleDiv.classList.add("class-title");
            looserTitleDiv.id = "looser-bracket";
            looserTitleDiv.textContent = raceClassCapital+": Looser Bracket"; // hard coded for now
            gridContainer.appendChild(looserTitleDiv);
        }
    }

    classContainer.appendChild(gridContainer);

    // directly after creating the nodes, attach the mouse events to the pilots
    // TODO check if this should be done after all diagrams on the page are rendered
    //attachPilotMouseEvents();

    // Render connection lines if necessary
    // Create an SVG element as a child of the classdisplay container
    if(nodes.settings.template !== "default") {
        const svgContainer = document.createElementNS("http://www.w3.org/2000/svg", "svg");
        svgContainer.setAttribute("id", `${raceClass}-svg`); // id is individual for each class
        svgContainer.setAttribute("class", "svg-container"); // class is always the same
        //svgContainer.innerHTML = ""; // Clear previous content

        // Get the dimensions of the grid container
        const gridWidth = gridContainer.offsetWidth;
        const gridHeight = gridContainer.offsetHeight;

        // Set the viewBox so that the center of the grid is at the center of the viewBox
        //svgContainer.setAttribute("viewBox", `0 0 ${gridWidth} ${gridHeight}`);

        // Render connections
        nodes.data.forEach(node => {
            if (node.parents) {
                node.parents.forEach(parentId => {
                    const parent = nodes.data.find(n => n.id === parentId);
                    if (parent) {
                        const startNode = nodeElements[parent.id];
                        const endNode = nodeElements[node.id];

                        // Get node positions relative to the grid container using offset properties
                        // TODO: since the size of the nodes is no longer fixed, the line positions are not correct anymore
                        // optionally use Math.round() to avoid subpixel rendering
                        const startX = startNode.offsetLeft + startNode.offsetWidth / 2;
                        const startY = startNode.offsetTop + startNode.offsetHeight / 2;
                        const endX = endNode.offsetLeft + endNode.offsetWidth / 2;
                        const endY = endNode.offsetTop + endNode.offsetHeight / 2;
                        const gapX = endNode.offsetLeft-(startNode.offsetLeft + startNode.offsetWidth);
                        const midX = startNode.offsetLeft + startNode.offsetWidth + (gapX / 2);

                        // Create SVG path
                        const path = document.createElementNS("http://www.w3.org/2000/svg", "path");
                        path.setAttribute("d", `M${startX},${startY} H${midX} V${endY} H${endX}`);
                        path.setAttribute("stroke", "black");
                        path.setAttribute("fill", "none");
                        path.setAttribute("stroke-width", "2");

                        // Append path to SVG container
                        svgContainer.appendChild(path);
                    }
                });
            }
        });

        //classContainer.appendChild(svgContainer);
        gridContainer.appendChild(svgContainer); // Testing different nesting to ease the scaling of the SVG elements
    }
}

function updateFilterAndHighlight() {
    selectedPilotId = parseInt(document.getElementById("pilotSelector").value) || 0;
    sessionStorage.setItem('cr_selectedPilotId', selectedPilotId);
    filterCheckboxState = document.getElementById("filterCheckbox").checked
    sessionStorage.setItem('cr_filterCheckboxState', filterCheckboxState);
    // filterBracketData calls calculateClassLayout and renderGrid
    //filterBracketData();
    updateAllClasses();
}

// Zoom level change detection
let lastDevicePixelRatio = window.devicePixelRatio;

window.addEventListener("resize", () => {
    if (window.devicePixelRatio !== lastDevicePixelRatio) {
        //console.log("Zoom level changed!");
        lastDevicePixelRatio = window.devicePixelRatio;
        // TODO do not rerender the whole grid, only refresh the SVG elements
        updateSvgScaling();
    }
});

// Attach event listeners to each element
function attachPilotMouseEvents() {
    // Select all pilot entries from all heats
    //const allHoverables = document.querySelectorAll("[class^='pilotid-']");

/*     document.querySelectorAll("div.pilot-entry").forEach((element) => {
        element.addEventListener("mouseover", (event) => {
            // Find the parent element with class 'pilot-entry'
            const parent = event.target.closest(".pilot-entry");
            if (parent) {
                // Find the class that starts with 'pilotid-'
                const pilotIdClass = Array.from(parent.classList).find((cls) =>
                    cls.startsWith("pilotid-")
                );

                if (pilotIdClass) {
                    //console.log(`Hovered over: ${pilotIdClass}`);
                    document.querySelectorAll(`.${pilotIdClass}`).forEach((el) => {
                        //el.style.color = "red"; // TODO use a CSS class instead of inline style
                        el.classList.add("hovered"); // Add a class for styling
                    });
                }
            }
        });

        element.addEventListener("mouseout", (event) => {
            const parent = event.target.closest(".pilot-entry");
                if (parent) {
                    const pilotIdClass = Array.from(parent.classList).find((cls) =>
                        cls.startsWith("pilotid-")
                    );

                    if (pilotIdClass) {
                        // Reset color for all elements with the same pilot ID class
                        document.querySelectorAll(`.${pilotIdClass}`).forEach((el) => {
                            //el.style.color = "black"; // TODO use a CSS class instead of inline style
                            el.classList.remove("hovered"); // Add a class for styling
                        });
                    }
                }
        });
    }); */


    document.addEventListener('mouseover', (event) => {
        // Check if the target element's class matches "pilotid-<number>"
        const target = event.target;
        if (target.className && target.parentNode.className.match(/pilotid-\d+/)) {
            const className = target.parentNode.className.match(/pilotid-\d+/)[0];
            // Change the text color to red for all elements with the same class
            document.querySelectorAll(`.${className}`).forEach((element) => {
                element.classList.add("hovered");
            });
        }
    });
    
    document.addEventListener('mouseout', (event) => {
        // Reset the text color when the mouse leaves
        const target = event.target;
        if (target.className && target.parentNode.className.match(/pilotid-\d+/)) {
            const className = target.parentNode.className.match(/pilotid-\d+/)[0];
            document.querySelectorAll(`.${className}`).forEach((element) => {
                element.classList.remove("hovered"); // Reset to the original color
            });
        }
    });
    
}

// Using defer option in the script tag to ensure that the script is executed after the DOM is fully loaded
// if(document.readyState == "complete") {
//     // when this is executed after the DOMContentLoaded event, the function is executed immediately
//     initData();
// }
// else {
//     window.addEventListener("load", (event) => {
//         initData();
//       });
// }
window.addEventListener("load", (event) => {
    initData();
  });