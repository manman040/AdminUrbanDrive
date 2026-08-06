# Urban Drive Admin Panel

A comprehensive admin interface for the Urban Drive ride-hailing application built with PHP and HTML.

## Features

- **Secure Login System**: Session-based authentication with admin credentials
- **Dashboard Overview**: Key metrics and analytics with interactive charts
- **Rides Management**: Complete ride tracking with filtering and search capabilities
- **Driver Applications**: Driver onboarding and approval system
- **Modern UI/UX**: Clean, responsive design with intuitive navigation
- **Firebase Ready**: Prepared for Firebase integration

## Pages

1. **Login Page** (`index.php`)
   - Secure admin authentication
   - Modern login interface with Urban Drive branding

2. **Dashboard** (`dashboard.php`)
   - Overview metrics (Total Rides, Revenue, Active Rides, Average Rating)
   - Interactive charts (Completion rate, Daily ride averages)
   - Tabbed interface for different views

3. **Rides Management** (`rides-management.php`)
   - Complete ride listing with search and filters
   - Status tracking (Completed, On Going, Cancel)
   - Payment method tracking

4. **Driver Requests** (`driver-requests.php`)
   - Driver application management
   - Approval/rejection system
   - Document verification interface

## Installation

1. **Prerequisites**
   - PHP 7.4 or higher
   - Web server (Apache/Nginx)
   - XAMPP/WAMP/MAMP (for local development)

2. **Setup**
   ```bash
   # Clone or download the project
   # Place in your web server directory (e.g., htdocs for XAMPP)
   
   # Start your web server
   # Access via: http://localhost/AdminUrbanDrive/
   ```

3. **Default Login Credentials**
   - Username: `admin` or `admin@urbandrive.com`
   - Password: `admin123`

## Firebase Integration

The project is prepared for Firebase integration. To connect with Firebase:

1. **Update Firebase Configuration**
   - Edit `firebase-config.php`
   - Replace placeholder values with your Firebase project credentials

2. **Enable Firebase Services**
   - Firebase Authentication
   - Firebase Realtime Database
   - Firebase Storage (for document uploads)

3. **Database Structure**
   ```json
   {
     "rides": {
       "ride_id": {
         "date_time": "2025-01-10 10:00:00",
         "customer": "Customer Name",
         "driver": "Driver Name",
         "destination": "KL to Genting",
         "fare": 11.00,
         "status": "completed",
         "payment": "cash"
       }
     },
     "driver_applications": {
       "application_id": {
         "driver_name": "John Smith",
         "car_type": "Toyota Camry",
         "plate_number": "ABC-1234",
         "phone_number": "+1234567890",
         "capacity": 4,
         "status": "pending",
         "documents": {
           "id_card": "url_to_document",
           "driving_license": "url_to_document",
           "car_grant": "url_to_document",
           "car_image": "url_to_document"
         }
       }
     }
   }
   ```

## File Structure

```
AdminUrbanDrive/
├── index.php                 # Login page
├── auth.php                  # Authentication handler
├── dashboard.php             # Main dashboard
├── rides-management.php      # Rides management
├── driver-requests.php       # Driver applications
├── logout.php               # Logout functionality
├── firebase-config.php      # Firebase configuration
└── README.md                # This file
```

## Customization

### Styling
- All styles are included in each PHP file for easy customization
- Primary color: `#ff6b35` (Orange)
- Secondary colors: Blue, Green, Purple for metrics cards

### Adding New Pages
1. Create new PHP file
2. Include session check at the top
3. Follow the existing layout structure
4. Add navigation link in sidebar

### Database Integration
1. Replace mock data arrays with Firebase calls
2. Use functions from `firebase-config.php`
3. Implement proper error handling

## Security Considerations

- **Production Deployment**
  - Use HTTPS
  - Implement proper Firebase Authentication
  - Add input validation and sanitization
  - Use prepared statements for database queries
  - Implement rate limiting

- **Session Management**
  - Sessions are used for authentication
  - Automatic logout on session expiry
  - Secure session configuration recommended

## Browser Support

- Chrome (recommended)
- Firefox
- Safari
- Edge

## License

This project is created for educational and development purposes.

## Support

For questions or issues:
1. Check the Firebase documentation for integration
2. Review PHP error logs for debugging
3. Ensure proper file permissions on web server

## Future Enhancements

- Real-time notifications
- Advanced analytics and reporting
- Mobile-responsive design improvements
- Multi-language support
- Advanced filtering and search
- Export functionality (PDF, Excel)
- Email notifications
- Push notifications
