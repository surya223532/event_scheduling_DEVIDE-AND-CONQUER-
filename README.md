# Scheduling Application

## Overview
This project is a scheduling application that allows users to manage events efficiently. Users can add multiple events, check for scheduling conflicts, and view events in a calendar format.

## Project Structure
The project consists of the following files:

- **index.php**: The main entry point for the application. It includes functionality for adding events, checking for conflicts, and displaying events in a calendar format.
  
- **event.php**: Contains functions and classes related to event management, such as creating, updating, or deleting events.
  
- **style.css**: Contains styles for the application, including layout, colors, fonts, and specific styles for the calendar display.
  
- **calendar.php**: Responsible for rendering the calendar view, displaying events on specific dates, and managing the layout of the calendar.
  
- **db.php**: Handles database connections and queries, used for storing and retrieving event data.

## Setup Instructions
1. **Clone the Repository**: 
   ```bash
   git clone <repository-url>
   cd scheduling
   ```

2. **Install Dependencies**: Ensure you have a local server environment set up (e.g., XAMPP, MAMP) to run PHP applications.

3. **Database Configuration**: 
   - Create a database for the application.
   - Update the `db.php` file with your database connection details.

4. **Run the Application**: 
   - Place the project folder in the server's root directory (e.g., `htdocs` for XAMPP).
   - Access the application via your web browser at `http://localhost/scheduling/index.php`.

## Usage Guidelines
- To add events, fill out the form on the main page and submit.
- The application will check for conflicts and suggest available time slots if necessary.
- Events can be viewed in a calendar format, allowing for easy management and scheduling.

## Contributing
Contributions are welcome! Please fork the repository and submit a pull request for any enhancements or bug fixes.

## License
This project is licensed under the MIT License. See the LICENSE file for more details.