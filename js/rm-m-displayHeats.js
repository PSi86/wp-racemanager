// rm-m-displayHeats.js
import { dataLoaderInstance } from './rm-m-dataLoader.js';
import { pilotSelectInstance } from './rm-m-pilotSelector.js';

// Exports const displayHeatsInstance = new DisplayHeats(); (at the bottom)

class DisplayHeats {
    constructor() {
        const configData = (window.RmJsConfig && window.RmJsConfig["displayHeats"]) || null;
        if (!configData) {
            throw new Error("displayHeats: Missing configuration data");
        }

        // Configuration properties
        // Read dependency configuration
        this.raceId = dataLoaderInstance.storageKey; // Load storageKey from dataLoader

        this.pilotSelectorId = pilotSelectInstance.pilotSelectorId; // Load pilotSelectorId from pilotSelector
        this.pilotSelectorElement = document.getElementById(`${this.pilotSelectorId}`);
        this.selectedPilotId = pilotSelectInstance.selectedPilotId;
        //console.log("DisplayHeats: initialized with selected pilot ID:", typeof(this.selectedPilotId), this.selectedPilotId);

        // Required properties
        // none

        // Optional properties
        this.filterCheckboxId = window.RmJsConfig["displayHeats"].filterCheckboxId || 'filterCheckbox';
        this.filterCheckboxElement = document.getElementById(`${this.filterCheckboxId}`);

        // Other properties       
        this.filterCheckboxKey = this.filterCheckboxId; //`filterOption` //  currently not using the race_id in the key (making it globally reusable) // `${this.raceId}_filterOption`
        this.filterCheckboxState = JSON.parse(sessionStorage.getItem(this.filterCheckboxKey)) || false; //|| null;

        this.cr_rh_data = null; // no default data, upon subscribing to dataLoader this will be populated
        
        // Zoom level change detection
        this.lastDevicePixelRatio = window.devicePixelRatio;
        
        // Run initialization
        //this.initialize();
        //setTimeout(() => this.initialize(), 1000); // Delay initialization to wait for the dataLoader to be ready
        
        // document.readyState === 'loading'
        if (document.readyState === 'complete') {
            console.log("DisplayHeats: Site is already loaded, initializing immediately");
            this.initialize();
          } else {
            // Otherwise, wait for the window load event.
            console.log("DisplayHeats: Registering load event listener");
            window.addEventListener('load', () => this.initialize());
          }
    }

    initialize() {
        // Init UI elements
        this.filterCheckboxElement.checked = this.filterCheckboxState;
        
        // Attach event handlers
        this.pilotSelectorElement.addEventListener('change', this.handleFilterChange.bind(this));
        this.filterCheckboxElement.addEventListener('change', this.handleFilterChange.bind(this));

        // Subscribe to the dataLoader (singleton)
        console.log("DisplayHeats: Subscribed to DataLoader");
        dataLoaderInstance.subscribe(this.handleDataLoaderEvent.bind(this));


        // Only attach new mouse eventhandler once after the data is loaded
        this.attachPilotMouseEvents(); // attach mouse events to the pilot names
        window.addEventListener("resize", () => {
            if (window.devicePixelRatio !== this.lastDevicePixelRatio) {
                //console.log("Zoom level changed!");
                this.lastDevicePixelRatio = window.devicePixelRatio;
                // TODO do not rerender the whole grid, only refresh the SVG elements
                this.updateSvgScaling();
            }
        });
    }

    handleFilterChange() {
        //this.selectedPilotId = event.target.value;
        this.selectedPilotId = parseInt(this.pilotSelectorElement.value) || 0; // could possibly be pulled from the pilotSelector instance
        this.filterCheckboxState = this.filterCheckboxElement.checked
        sessionStorage.setItem(this.filterCheckboxKey, this.filterCheckboxState);

        console.log('DisplayHeats: Filter changed:', this.selectedPilotId, this.filterCheckboxState);
        // Filter and display heats for the selected pilot
        // filterBracketData calls calculateClassLayout and renderGrid
        //filterBracketData();
        this.updateAllClasses();
    }
    
    handleDataLoaderEvent(data) {
        console.log('DisplayHeats: Received data:', data);
        // Extract and display heats for the selected pilot
        this.cr_rh_data = data;
        this.updateAllClasses();
    }

    updateAllClasses() {
        // find raceclass-container on the page and update them
        const classContainers = document.getElementsByClassName("raceclass-container");
        for (let i = 0; i < classContainers.length; i++) {
            //updateClass(classContainers[i]); // Object instead of ID
            if(classContainers[i].style.display != "none") {
                // only update visible class-displays
                this.updateClass(classContainers[i].id); // ID instead of Object
            }
        }
    }
    
    updateClass(containerId) {
        const raceClass = containerId.substring(0, containerId.indexOf("-display")); // eg:"elimination"
        console.log("DisplayHeats: Updating class display: " + containerId);
        // Todo: Accept class parameter to update only one class or empty parameter for all classes
        let nodes=this.updateClassData(this.cr_rh_data, raceClass);
        nodes=this.filterBracketData(nodes);
        nodes=this.calculateClassLayout(nodes);
        //renderGrid(nodes);
        this.renderGrid(nodes, raceClass);
    }

    updateSvgScaling() {
        // find all svg elements and redraw them
        console.log("DisplayHeats: Redrawing SVG elements");
        // TODO: implement svg redraw
        // to do this we need the nodes data for each class-display container -> locally store them per container
        // workaround: recreate nodes data for each container and redraw the svg elements using the standard renderGrid function
        this.updateAllClasses();
    }

    // Attach event listeners to each element
    attachPilotMouseEvents() {
        // Select all pilot entries from all heats
        //const allHoverables = document.querySelectorAll("[class^='pilotid-']");

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

    getCurrentClassName() {
        // get the currently active class from the RHData
        function findRaceClassByHeatId(id) {
            const race = this.cr_rh_data.heat_data.heats.find(cls => cls.id === id); // .class_data. instead of .classes.?
            return race ? race.class_id : null;
        }
        let currentClassId=findRaceClassByHeatId(this.cr_rh_data.current_heat.current_heat);
        if(currentClassId) {
            const currentClass=this.cr_rh_data.class_data.classes.find(cls => cls.id === currentClassId);
            return currentClass.displayname.toLowerCase();
        } 
        else {
            return null;
        }
    }

    // Function to update the visibility of the class displays based on the current class
    // Currently not in use in Webhosted version
    showOnlyActiveClass(raceClass) {
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


    // Function to update bracketData with eliminationHeats
    // Copy necessary data from Input (rhData) to Output (bracketData)
    // Original heat id is stored in .rh_id per heat
    // Current heat id is stored in .currentHeat

    // TODO eliminate writing to global variable bracketData, use return value instead or use a variable for this class only instead of overwriting the global template
    updateClassData(rhData, raceClass) { // rhData is the data from RotorHazard, raceClass is the name of the class to be displayed
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
            console.warn("updateClassData: raceClass not found in RHData");
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
                //throw new Error(`Target element with id=${targetId} not found in bracketData`);
                console.warn(`Target element with id=${targetId} not found in bracketData`);
            }

            // Update the target element with the new data
            targetElement.rh_id = heat.id; // Add the new rh_id element
            targetElement.title = heat.displayname; // Map displayname to title

            // add flag for active heat (used in rendering)
            if (heat.id === bracketData.settings.currentHeat) {
                targetElement.active = true;
            }

            // rhData.result_data.heats.find
            // Cant use getLeaderboardForHeat(resultHeatsArray, heat_id) because we need 
            // the whole result_data for the heat for the flown rounds
            let heatResults = null;
            let leaderboard = null;
            let time = null;

            // Skip searching for results if the heat is not flown yet
            if(heat.id <= bracketData.settings.currentHeat) {
                heatResults = resultHeatsArray ? resultHeatsArray.find(h => h.heat_id === heat.id) : null;
            }

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
                let pilotCallsign = "";
                let pilotResult = "";
                let pilotId = 0;

                // first check if results are available for the heat if(leaderboard)
                // if not, use the seed_id to display the heat displayname and rank
                // if yes, display the pilot callsign and result

                // Switch between seeded and unseeded slots!
                // TODO: cant remember why node_index could be null -> check if this is still necessary
                // slot.method is 1 for seeded slots and 0 for unseeded slots (and -1 for empty slots?)

                if ((slot.pilot_id === 0 || slot.node_index === null) && !heatResultAvailable && slot.method !== -1) {
                    // Slot not seeded
                    // could just get this data from the result_data
                    //result_data.heats[4].rounds[flownRounds].nodes[i].pilot_id

                    // Skip searching for results if the heat to seed from is not flown yet
                    if(slot.seed_id <= bracketData.settings.currentHeat) {
                        
                        let seedHeatLeaderboard = this.getLeaderboardForHeat(resultHeatsArray, slot.seed_id)
                        let seedPilotLeaderboard = null;

                        if(seedHeatLeaderboard) {
                            //const seedPilot = seedHeatLeaderboard.find(r => r.node_index === i);
                            seedPilotLeaderboard = seedHeatLeaderboard.find(entry => entry.position == slot.seed_rank);
                        }
                        if(seedPilotLeaderboard) {
                            pilotCallsign = seedPilotLeaderboard.callsign;
                            pilotId = seedPilotLeaderboard.pilot_id;
                        }
                    }
                    
                    if(!pilotCallsign) {
                        // Resolve seed_id to heat displayname
                        const seedHeat = rhData.heat_data.heats.find(h => h.id === slot.seed_id);
                        // Fallback: show seeding rule (source heat and source rank)
                        pilotCallsign = seedHeat ? `${seedHeat.displayname} #${slot.seed_rank}` : "";
                    }
                } 
                //else if (slot.pilot_id !== 0 && slot.node_index !== null && heatResultAvailable) {
                else if (slot.pilot_id !== 0 && slot.node_index !== null) {
                    // Slot is seeded
                    pilotId = slot.pilot_id;
                    const pilot = rhData.pilot_data.pilots.find(p => p.pilot_id === slot.pilot_id);
                    //const pilotLeaderboard = null;
                    pilotCallsign = pilot ? pilot.callsign : "";

                    if(leaderboard) { // check if results are available
                        const pilotLeaderboard = leaderboard.find(r => r.pilot_id === slot.pilot_id);
                        
                        if (pilotLeaderboard[time] === "0:00.000" || pilotLeaderboard.position < 1) {
                            pilotResult = "DNF";
                        } else {
                            pilotResult = String(pilotLeaderboard[time] + " ");  // total_time
                            pilotResult += String(" L" + pilotLeaderboard.laps ); // Number of laps
                            pilotResult += String(" #" + pilotLeaderboard.position); // final position
                        }
                    }
                }

                if (targetElement.pilots[i]) {
                    targetElement.pilots[i].id = pilotId;
                    targetElement.pilots[i].name = pilotCallsign;
                    targetElement.pilots[i].result = pilotResult;
                } else {
                    targetElement.pilots.push({
                        id: pilotId,
                        name: pilotCallsign,
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

    // Generate Bracket Representation
    // Calculate the grid layout for the bracketData (gridColumn, gridRow)
    calculateClassLayout(bracketData) {
        if (!bracketData) {
            console.warn("calculateClassLayout: No bracketData for this class");
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

    // Filter 
    filterBracketData(bracketData) {
        if (!bracketData) {
            console.warn("filterBracketData: Data input is missing");
            return null;
        }
        // TODO: when filtering sometimes unnecessary races are shown; also unnecessary blank columns are shown
        const filterPilotId = this.selectedPilotId;
        const filterActive = this.filterCheckboxState;
        let filteredBracketData = {};
    
        if (filterPilotId > 0 && filterActive) {
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
            filteredBracketData = bracketData; // Reset to full data
        }
        return filteredBracketData;
        //const nodes = calculateClassLayout(filteredBracketData);
        //renderGrid(nodes);
    }

    // Render the grid with nodes and connections
    // TODO carry over the node content from renderNodes (previous implementation)
    renderGrid(nodes, raceClass) {
        if (!nodes || !raceClass) {
            console.warn("renderGrid: Missing nodes or raceClass");
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

        const filterPilotId = this.selectedPilotId;
        
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
                    if(pilot.id === filterPilotId && filterPilotId !== 0) {
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

                if (!foundSelectedPilot && filterPilotId !== 0) {
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

    /**
     * Retrieves the Leaderboard for a heat_id.
     *
     * This function follows these steps:
     * 1. Finds the heat with heat_id matching heat_id.
     * 2. Checks heat.leaderboard.meta.primary_leaderboard to determine which subelement contains the pilot data.
     * 3. Uses the primary leaderboard key to obtain the pilot leaderboard array.
     * 4. Searches that array for the pilot whose 'position' matches seed_rank.
     *
     * @param {Array} resultHeatsArray - The JSON object containing race result details.
     * @param {number|string} heat_id - The identifier used as heat_id.
     * @returns {string|null} - The pilot name if found; otherwise, null.
     */
    getLeaderboardForHeat(resultHeatsArray, heat_id) {
        // Verify the basic structure exists
        /* if (!rhData || !rhData.result_data || !Array.isArray(rhData.result_data.heats)) {
            return null;
        } */
        const heat = resultHeatsArray ? resultHeatsArray.find(h => h.heat_id === heat_id) : null;
            
        // Find the heat using heat_id (as heat_id)
        //const heat = rhData.result_data.heats.find(h => h.heat_id == heat_id);
        if (!heat || !heat.leaderboard || !heat.leaderboard.meta || !heat.leaderboard.meta.primary_leaderboard) {
            return null;
        }
        
        // Determine the primary leaderboard key from metadata (as per line 553 and following)
        const primaryKey = heat.leaderboard.meta.primary_leaderboard;
        
        // Retrieve the pilot leaderboard using that key
        const heatLeaderboard = heat.leaderboard[primaryKey];
        // TODO: check if conversion to array is necessary like in line 553
        if (!Array.isArray(heatLeaderboard)) {
            return null;
        }
        
        // Look for the pilot whose 'position' matches seed_rank
        //const pilotEntry = heatLeaderboard.find(entry => entry.position == seed_rank);
        
        // pilotEntry: callsign, pilot_id
        return heatLeaderboard;
    }

}
export const displayHeatsInstance = new DisplayHeats();