# Wordpress
Litmos plugin for Wordpress
This plug-in imports your courses from the Litmos Learning Platform by CallidusCloud.

After activating the plug-in you will see the Litmos option in the Settings pages.
On the settings page, enter your Litmos API credentials to enable the automatic course sync.
There is also a link "Import Litmos Courses Now" that allows for manual course import.
Course import will import all fields available from Litmos into Wordpress custom fields.
After course import an archive page can be found here: ?post_type=litmos_course
Course sign in without authenticating with Litmos can be used by appending &login=1 to litmos course posts.
E.G. ?litmos_course=litmos-getting-started&login=1
Users must have their Litmos username entered in their profile to use the sign-in links.
If the login fails, an error message will be appended to the url with parameter name "errMsg"


